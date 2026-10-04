<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Http\Resources\Web\VideoDetail;
use App\Models\Video;
use App\Support\DeletePin;
use App\Support\HumanBytes;
use App\Support\LibraryCleaner;
use App\Support\MediaUnavailable;
use App\Support\Toast;
use App\Support\UpNext;
use App\Support\VideoSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class VideoController extends Controller
{
    private const int PER_PAGE = 24;

    private const int UP_NEXT_SIZE = 20;

    private const int LOOKUP_LIMIT = 300;

    /** Сколько карточек отдавать уведомлениям: больше — вкладка покажет одну сводку. */
    private const int DOWNLOADED_LIMIT = 5;

    /** Дальше в прошлое уведомления не заглядывают, как бы давно вкладку ни открывали. */
    private const int DOWNLOADED_LOOKBACK_DAYS = 7;

    public function index(Request $request): Response
    {
        $sort = VideoSort::fromRequest($request);

        $videos = VideoSort::apply(Video::query(), $sort)
            ->with('channel:id,name,thumbnail,is_playlist')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('feed', [
            'sort' => $sort,
            'videos' => Inertia::scroll(VideoCard::collection($videos)),
        ]);
    }

    /**
     * Свежие карточки видео по id — для «Продолжить просмотр»: список живёт
     * в localStorage и не знает, что видео с тех пор удалили.
     */
    public function lookup(Request $request): JsonResponse
    {
        $ids = collect(Arr::wrap($request->query('ids')))
            ->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(self::LOOKUP_LIMIT)
            ->values();

        $videos = $ids->isEmpty() ? collect() : Video::query()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->whereKey($ids)
            ->get();

        return response()->json([
            'data' => VideoCard::collection($videos)->resolve(),
        ]);
    }

    /**
     * Что скачалось с момента since — для уведомлений «видео готово» в открытой
     * вкладке. Курсор — время сервера (now из прошлого ответа), часы браузера
     * ни при чём. Без since — только курсор: при первом заходе старое не всплывает.
     * Границу берём включительно (downloaded_at хранится с точностью до секунды),
     * повторы вкладка отсеивает по id.
     */
    public function downloaded(Request $request): JsonResponse
    {
        $now = now()->startOfSecond();
        $cursor = $now->toIso8601String();
        $since = $this->parseSince($request->query('since'));

        if ($since === null) {
            return response()->json(['now' => $cursor, 'total' => 0, 'data' => []]);
        }

        $from = $since->max($now->copy()->subDays(self::DOWNLOADED_LOOKBACK_DAYS));

        $downloaded = Video::query()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->where('downloaded_at', '>=', $from)
            ->orderByDesc('downloaded_at')
            ->orderByDesc('id');

        $total = $downloaded->count();
        $videos = VideoCard::collection($downloaded->limit(self::DOWNLOADED_LIMIT)->get())->resolve();

        return response()->json([
            'now' => $cursor,
            'total' => $total,
            'data' => $videos,
        ]);
    }

    /**
     * Скачанное видео — с плеером, из каталога — с кнопкой загрузки.
     */
    public function show(Video $catalogVideo): Response
    {
        $video = $catalogVideo->load('channel');
        $video->channel->loadCount('videos');

        $upNext = UpNext::for($video, self::UP_NEXT_SIZE);

        return Inertia::render('watch', [
            'video' => (new VideoDetail($video))->resolve(),
            'channel' => (new ChannelCard($video->channel))->resolve(),
            'upNext' => VideoCard::collection($upNext)->resolve(),
        ]);
    }

    private function parseSince(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Удаляет файл видео насовсем: строка остаётся меткой, чтобы парсер
     * не завёл видео заново, а воркер не скачал его ещё раз.
     */
    public function destroy(Request $request, Video $video, LibraryCleaner $cleaner, DeletePin $deletePin): RedirectResponse
    {
        $deletePin->authorize($request);

        try {
            $removal = $cleaner->removeVideo($video);
        } catch (MediaUnavailable) {
            Toast::error('Медиадиск недоступен — видео не удалено');

            return back();
        }

        if ($removal->hasLeftovers()) {
            Toast::error(
                'Видео скрыто, но файл удалить не удалось',
                'Проверьте права на медиадиск — подробности в логах сервиса в Dokploy.',
            );
        } else {
            $freed = $removal->freedBytes > 0
                ? 'Освобождено '.HumanBytes::format($removal->freedBytes).'. '
                : '';

            Toast::success("Видео «{$video->name}» удалено", $freed.'Скачиваться снова оно не будет.');
        }

        return to_route('channels.show', $video->channel_id);
    }
}
