<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreChannelRequest;
use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Models\Channel;
use App\Support\DeletePin;
use App\Support\HumanBytes;
use App\Support\LibraryCleaner;
use App\Support\Toast;
use App\Support\VideoSort;
use App\Support\Youtube\ChannelImporter;
use App\Support\Youtube\ImportFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Каналы и плейлисты в веб-клиенте. Добавление и удаление — в тех же
 * сервисах, что и у консольных команд youtube:add-* и youtube:remove-channel.
 */
class ChannelController extends Controller
{
    private const int PER_PAGE = 24;

    public function index(): Response
    {
        $channels = Channel::query()
            ->withCount('videos')
            ->withCount($this->queuedCount())
            ->withSum('videos', 'file_size')
            ->withMax('videos', 'published_at')
            ->orderByRaw('videos_max_published_at IS NULL')
            ->orderByDesc('videos_max_published_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('channels', [
            'channels' => ChannelCard::collection($channels)->resolve(),
        ]);
    }

    public function show(Request $request, Channel $channel): Response
    {
        $sort = VideoSort::fromRequest($request);

        $channel->loadCount(['videos', ...$this->queuedCount()])
            ->loadSum('videos', 'file_size')
            ->loadSum('videos', 'duration_seconds')
            ->loadMax('videos', 'published_at');

        $videos = VideoSort::apply($channel->videos()->getQuery(), $sort)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('channel', [
            'channel' => (new ChannelCard($channel))->resolve(),
            'sort' => $sort,
            'videos' => Inertia::scroll(VideoCard::collection($videos)),
        ]);
    }

    public function store(StoreChannelRequest $request, ChannelImporter $importer): RedirectResponse
    {
        $isPlaylist = $request->isPlaylist();
        $warnings = [];

        try {
            $channel = $isPlaylist
                ? $importer->importPlaylist(
                    $request->channelUrl(),
                    name: $request->playlistName(),
                    thumbnail: $request->thumbnail(),
                    sourceChannel: $request->sourceChannel(),
                    parseLatest: $request->parseLatest(),
                    parsePopular: $request->parsePopular(),
                    warn: function (string $message) use (&$warnings): void {
                        $warnings[] = $message;
                    },
                )
                : $importer->importChannel(
                    $request->channelUrl(),
                    parseLatest: $request->parseLatest(),
                    parsePopular: $request->parsePopular(),
                    syncFrom: $request->syncFrom(),
                    playlistId: $request->playlistId(),
                );
        } catch (ImportFailed $e) {
            throw ValidationException::withMessages([
                'url' => $this->importFailedMessage($e, $isPlaylist),
            ]);
        }

        // Не ждать планировщика (он раз в 10 минут): список видео подтянется
        // сразу после ответа, а скачает их воркер в обычном порядке.
        defer(fn () => Artisan::call('youtube:parse-videos', ['--channel' => $channel->id]));

        $kind = $isPlaylist ? 'плейлист' : 'канал';
        $status = $channel->wasRecentlyCreated ? 'Добавлен' : 'Уже был добавлен';

        // Единственное предупреждение импорта — не найден канал-источник.
        $description = $warnings === []
            ? 'Список видео обновится через минуту, скачивание — в порядке очереди.'
            : 'Канал-источник не найден — взята обложка плейлиста. Список видео обновится через минуту.';

        Toast::success("{$status} {$kind} «{$channel->name}»", $description);

        return to_route('channels.show', $channel);
    }

    public function destroy(Request $request, Channel $channel, LibraryCleaner $cleaner, DeletePin $deletePin): RedirectResponse
    {
        $deletePin->authorize($request);

        $summary = $cleaner->channelSummary($channel);

        // Без медиадиска удалились бы только записи, а гигабайты файлов
        // остались бы лежать сиротами. Консольная команда это умеет с
        // предупреждением, из веба — не даём.
        if (! $summary['media_available']) {
            Toast::error('Медиадиск недоступен — канал не удалён');

            return back();
        }

        $removal = $cleaner->removeChannel($channel, $summary);

        if ($removal->hasLeftovers()) {
            Toast::error(
                "Канал «{$channel->name}» удалён, но часть файлов удалить не удалось",
                'Проверьте права на медиадиск — подробности в логах сервиса в Dokploy.',
            );
        } else {
            $freed = $summary['media_bytes'] > 0
                ? 'Освобождено '.HumanBytes::format($summary['media_bytes']).'.'
                : null;

            Toast::success("Канал «{$channel->name}» удалён", $freed);
        }

        return to_route('channels.index');
    }

    /**
     * Сколько видео ждёт воркера. Отношение videos отфильтровано глобальным
     * скоупом «только скачанные», поэтому скоуп снимаем явно.
     *
     * @return array<string, \Closure>
     */
    private function queuedCount(): array
    {
        return ['videos as queued_count' => fn ($query) => $query
            ->withoutGlobalScopes()
            ->where('is_downloaded', false)
            ->where('is_unavailable', false)];
    }

    private function importFailedMessage(ImportFailed $e, bool $isPlaylist): string
    {
        return match (true) {
            $e->reason === ImportFailed::BAD_URL => 'Не получилось разобрать адрес. Вставьте ссылку на канал (youtube.com/@…) или плейлист (…?list=…).',
            $isPlaylist => 'Плейлист не найден. Закрытые плейлисты не видны — сделайте его доступным по ссылке.',
            default => 'Канал не найден на YouTube.',
        };
    }
}
