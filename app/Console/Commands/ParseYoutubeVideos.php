<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Video;
use App\Support\LibraryCleaner;
use App\Support\StoresPublicThumbnail;
use Carbon\Carbon;
use DateInterval;
use Google\Service\YouTube;
use Illuminate\Console\Command;

class ParseYoutubeVideos extends Command
{
    protected $signature = 'youtube:parse-videos {--channel= : Parse videos only for the given channel id} {--popular : Parse popular channel videos instead of latest uploads}';

    protected $description = 'Парсинг новых видео с подписанных каналов';

    public function handle(
        YouTube $youTube,
        StoresPublicThumbnail $thumbnailStore,
        LibraryCleaner $cleaner,
    ) {
        $channelId = $this->option('channel');

        $channels = Channel::query()
            ->when($channelId, fn ($query) => $query->whereKey($channelId))
            ->get();

        $parsePopular = (bool) $this->option('popular');

        $this->logVerbose(sprintf(
            'Режим: %s; каналов к обработке: %d%s',
            $parsePopular ? 'popular' : 'latest',
            $channels->count(),
            $channelId ? "; фильтр channel={$channelId}" : '',
        ));

        if ($channels->isEmpty()) {
            $this->writeError('Не найдено каналов для обработки');

            return self::FAILURE;
        }

        foreach ($channels as $channel) {
            $this->writeInfo("Проверка канала: $channel->name");

            try {
                if ($parsePopular && ! $channel->parse_popular) {
                    $this->writeInfo('Парсинг популярных видео отключен для канала');

                    continue;
                }

                if (! $parsePopular && ! $channel->parse_latest) {
                    $this->writeInfo('Парсинг последних видео отключен для канала');

                    continue;
                }

                $uploadsPlaylistId = $channel->uploads_playlist_id ?: ($channel->is_playlist
                    ? null
                    : $this->fetchUploadsPlaylistId($youTube, $channel->external_id));

                if (! $uploadsPlaylistId) {
                    $this->writeError("Не удалось определить uploads playlist для $channel->external_id");

                    continue;
                }

                if ($channel->uploads_playlist_id !== $uploadsPlaylistId) {
                    $channel->update(['uploads_playlist_id' => $uploadsPlaylistId]);
                }

                $this->logVerbose("uploads playlist: $uploadsPlaylistId");

                if ($parsePopular) {
                    $playlistItems = [];
                    $popularVideos = $this->fetchPopularVideos($youTube, $uploadsPlaylistId);
                    $popularVideoIds = collect($popularVideos)
                        ->pluck('id')
                        ->values()
                        ->all();
                    $updatedViewCounts = $this->updateExistingVideoViewCounts($channel->id, $popularVideos);
                    $this->logVerbose(sprintf(
                        'Получено популярных видео id: %d; обновлено view_count у существующих видео: %d',
                        count($popularVideoIds),
                        $updatedViewCounts,
                    ));
                } elseif ($channel->is_playlist) {
                    // Ручной плейлист отсортирован по position, а не по дате, и видео в него
                    // добавляют задним числом: обрезать выборку по last_synced_at нельзя,
                    // поэтому проходим его целиком. Заодно видно, что из него убрали.
                    $playlistItems = $this->fetchPlaylistItems($youTube, $uploadsPlaylistId);
                    $popularVideoIds = [];
                    $this->logVerbose('Получено playlist items (плейлист целиком): '.count($playlistItems));
                } else {
                    if ($channel->last_synced_at) {
                        $playlistItems = $this->fetchNewPlaylistItems($youTube, $uploadsPlaylistId, $channel->last_synced_at);
                        $this->logVerbose(sprintf(
                            'Получено новых playlist items: %d после %s',
                            count($playlistItems),
                            $channel->last_synced_at->toDateTimeString(),
                        ));
                    } else {
                        $playlistItems = $this->fetchLatestPlaylistItems($youTube, $uploadsPlaylistId);
                        $this->logVerbose('Получено последних playlist items: '.count($playlistItems));
                    }

                    $popularVideoIds = [];
                }

                if ($playlistItems === [] && $popularVideoIds === []) {
                    $this->writeInfo('Новых загрузок нет');

                    continue;
                }

                $latestPublishedAt = collect($playlistItems)
                    ->map(fn ($item) => Carbon::parse($item->snippet->publishedAt))
                    ->max();

                $videoIds = collect($playlistItems)
                    ->map(fn ($item) => $item->snippet->resourceId->videoId ?? null)
                    ->merge($popularVideoIds)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($channel->is_playlist && ! $parsePopular) {
                    $this->syncPlaylistMembership($cleaner, $channel, $videoIds);
                }

                // Удалённые вручную видео не трогаем: иначе парсер вернул бы им
                // обложку, а то и перетащил бы в другой канал.
                $removedVideoIds = Video::withoutGlobalScopes()
                    ->whereIn('external_id', $videoIds)
                    ->whereNotNull('removed_at')
                    ->pluck('external_id')
                    ->all();

                if ($removedVideoIds !== []) {
                    $videoIds = array_values(array_diff($videoIds, $removedVideoIds));
                    $this->logVerbose('Пропущено удалённых вручную видео: '.count($removedVideoIds));
                }

                if ($videoIds === []) {
                    $this->writeInfo('Видео для сохранения не найдены');

                    continue;
                }

                $savedVideosCount = 0;
                $updatedVideosCount = 0;
                $skippedShortVideosCount = 0;
                $skippedLongVideosCount = 0;

                foreach (array_chunk($videoIds, 50) as $videoIdsChunk) {
                    $this->logVeryVerbose('Запрос подробностей по видео: '.implode(',', $videoIdsChunk));

                    $existingVideos = Video::withoutGlobalScopes()
                        ->with('channel:id,is_playlist')
                        ->whereIn('external_id', $videoIdsChunk)
                        ->get(['id', 'external_id', 'channel_id', 'thumbnail'])
                        ->keyBy('external_id');

                    $videosResponse = $youTube->videos->listVideos('snippet,contentDetails,statistics', [
                        'id' => implode(',', $videoIdsChunk),
                    ]);

                    foreach ($videosResponse->getItems() as $video) {
                        $durationSeconds = $this->durationInSeconds($video->contentDetails->duration ?? null);

                        if ($durationSeconds < config('app.videos.min_video_duration')) {
                            $skippedShortVideosCount++;
                            $this->logVeryVerbose(sprintf(
                                'Пропуск короткого видео %s (%d сек): %s',
                                $video->id,
                                $durationSeconds,
                                $video->snippet->title,
                            ));

                            continue;
                        }

                        if ($durationSeconds > config('app.videos.max_video_duration')) {
                            $skippedLongVideosCount++;
                            $this->logVeryVerbose(sprintf(
                                'Пропуск длинного видео %s (%d сек): %s',
                                $video->id,
                                $durationSeconds,
                                $video->snippet->title,
                            ));

                            continue;
                        }

                        $publishedAt = Carbon::parse($video->snippet->publishedAt);
                        $thumbnailUrl = $video->snippet->thumbnails->medium->url
                            ?? $video->snippet->thumbnails->high->url
                            ?? $video->snippet->thumbnails->default->url
                            ?? null;

                        $thumbnail = $thumbnailStore->replaceFromUrl(
                            $thumbnailUrl,
                            $existingVideos->get($video->id)?->getRawOriginal('thumbnail'),
                            'thumbnails/videos',
                            $video->id,
                        );

                        $storedVideo = Video::withoutGlobalScopes()->updateOrCreate(
                            ['external_id' => $video->id],
                            [
                                'channel_id' => $this->ownerId($existingVideos->get($video->id), $channel),
                                'name' => $video->snippet->title,
                                'description' => $video->snippet->description,
                                'thumbnail' => $thumbnail,
                                'duration_seconds' => $durationSeconds,
                                'view_count' => (int) ($video->statistics->viewCount ?? 0),
                                'published_at' => $publishedAt,
                            ],
                        );

                        $channel->videos()->syncWithoutDetaching([$storedVideo->id]);

                        if ($storedVideo->wasRecentlyCreated) {
                            $savedVideosCount++;
                            $this->logVeryVerbose("Создано видео {$video->id}: {$video->snippet->title}");
                        } else {
                            $updatedVideosCount++;
                            $this->logVeryVerbose("Обновлено видео {$video->id}: {$video->snippet->title}");
                        }
                    }
                }

                if ($channel->is_playlist) {
                    // Для плейлиста дата в playlist item — это дата добавления, она ничего
                    // не отсекает, так что поле означает просто «когда прогоняли последний раз».
                    $channel->update(['last_synced_at' => now()]);
                } elseif ($latestPublishedAt && (! $channel->last_synced_at || $latestPublishedAt->gt($channel->last_synced_at))) {
                    $channel->update(['last_synced_at' => $latestPublishedAt]);
                }

                $this->writeInfo("Добавлено видео: {$savedVideosCount}");
                $this->logVerbose(sprintf(
                    'Итог по каналу: обработано id=%d, создано=%d, обновлено=%d, пропущено коротких=%d, пропущено длинных=%d',
                    count($videoIds),
                    $savedVideosCount,
                    $updatedVideosCount,
                    $skippedShortVideosCount,
                    $skippedLongVideosCount,
                ));

            } catch (\Exception $e) {
                $this->writeError("Ошибка для {$channel->external_id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Основной источник видео. Новое — тому, кто его нашёл; уже известное
     * переходит от плейлиста к каналу автора, когда тот тоже отслеживается,
     * а в остальных случаях остаётся где было.
     */
    private function ownerId(?Video $existing, Channel $channel): int
    {
        if (! $existing) {
            return $channel->id;
        }

        if (! $channel->is_playlist && $existing->channel?->is_playlist) {
            return $channel->id;
        }

        return $existing->channel_id;
    }

    /**
     * Отвязывает от плейлиста видео, которых в нём на YouTube больше нет.
     * Пустой ответ ничего не отвязывает: плейлист скорее недоступен, чем пуст.
     *
     * @param  list<string>  $listedVideoIds
     */
    private function syncPlaylistMembership(LibraryCleaner $cleaner, Channel $channel, array $listedVideoIds): void
    {
        if ($listedVideoIds === []) {
            return;
        }

        $gone = $channel->videos()
            ->withoutGlobalScopes()
            ->whereNotIn('external_id', $listedVideoIds)
            ->get();

        if ($gone->isEmpty()) {
            return;
        }

        ['detached' => $detached, 'purged' => $purged] = $cleaner->detachVideos($channel, $gone);

        $this->writeInfo("Убрано из плейлиста: {$detached}, удалено совсем: {$purged}");
    }

    private function fetchUploadsPlaylistId(YouTube $youTube, string $channelId): ?string
    {
        $response = $youTube->channels->listChannels('contentDetails', [
            'id' => $channelId,
            'maxResults' => 1,
        ]);

        $item = $response->getItems()[0] ?? null;

        return $item?->contentDetails?->relatedPlaylists?->uploads;
    }

    private function fetchNewPlaylistItems(YouTube $youTube, string $uploadsPlaylistId, Carbon $after): array
    {
        $items = [];
        $pageToken = null;

        do {
            $response = $youTube->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 50,
                'pageToken' => $pageToken,
            ]);

            $stopPagination = false;

            foreach ($response->getItems() as $item) {
                $publishedAt = Carbon::parse($item->snippet->publishedAt);

                if ($publishedAt->lte($after)) {
                    $stopPagination = true;
                    break;
                }

                $items[] = $item;
            }

            if ($stopPagination) {
                break;
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    private function fetchLatestPlaylistItems(YouTube $youTube, string $uploadsPlaylistId, int $limit = 50): array
    {
        return $this->fetchPlaylistItems($youTube, $uploadsPlaylistId, $limit);
    }

    private function fetchPopularVideos(
        YouTube $youTube,
        string $uploadsPlaylistId,
        int $limit = 50,
        int $minDurationSeconds = 4 * 60,
    ): array {
        $playlistItems = $this->fetchPlaylistItems($youTube, $uploadsPlaylistId);
        $videoIds = collect($playlistItems)
            ->map(fn ($item) => $item->snippet->resourceId->videoId ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($videoIds === []) {
            return [];
        }

        $videos = [];

        foreach (array_chunk($videoIds, 50) as $videoIdsChunk) {
            $response = $youTube->videos->listVideos('contentDetails,statistics', [
                'id' => implode(',', $videoIdsChunk),
                'maxResults' => count($videoIdsChunk),
            ]);

            foreach ($response->getItems() as $video) {
                $durationSeconds = $this->durationInSeconds($video->contentDetails->duration ?? null);

                if ($durationSeconds < $minDurationSeconds) {
                    continue;
                }

                $videos[] = [
                    'id' => $video->id,
                    'viewCount' => (int) ($video->statistics->viewCount ?? 0),
                ];
            }
        }

        return collect($videos)
            ->sortByDesc('viewCount')
            ->take($limit)
            ->values()
            ->all();
    }

    private function updateExistingVideoViewCounts(int $channelId, array $popularVideos): int
    {
        if ($popularVideos === []) {
            return 0;
        }

        $updatedVideosCount = 0;

        foreach (array_chunk($popularVideos, 500) as $videosChunk) {
            $viewCountsByExternalId = collect($videosChunk)
                ->mapWithKeys(fn (array $video) => [$video['id'] => $video['viewCount']]);

            $existingVideos = Video::withoutGlobalScopes()
                ->whereHas('channels', fn ($query) => $query->whereKey($channelId))
                ->whereIn('external_id', $viewCountsByExternalId->keys())
                ->get(['id', 'external_id', 'view_count']);

            foreach ($existingVideos as $existingVideo) {
                $nextViewCount = $viewCountsByExternalId->get($existingVideo->external_id);

                if ($nextViewCount === null || $existingVideo->view_count === $nextViewCount) {
                    continue;
                }

                $existingVideo->forceFill(['view_count' => $nextViewCount])->save();
                $updatedVideosCount++;
            }
        }

        return $updatedVideosCount;
    }

    private function fetchPlaylistItems(YouTube $youTube, string $uploadsPlaylistId, ?int $limit = null): array
    {
        $items = [];
        $pageToken = null;

        do {
            if ($limit !== null && count($items) >= $limit) {
                break;
            }

            $response = $youTube->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => $limit !== null ? min($limit - count($items), 50) : 50,
                'pageToken' => $pageToken,
            ]);

            foreach ($response->getItems() as $item) {
                $items[] = $item;
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $items;
    }

    private function durationInSeconds(?string $duration): int
    {
        if (! $duration) {
            return 0;
        }

        $interval = new DateInterval($duration);

        return ($interval->d * 24 * 60 * 60)
            + ($interval->h * 60 * 60)
            + ($interval->i * 60)
            + $interval->s;
    }

    private function logVerbose(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->writeLine($message);
        }
    }

    private function logVeryVerbose(string $message): void
    {
        if ($this->output->isVeryVerbose()) {
            $this->writeLine($message);
        }
    }

    private function writeInfo(string $message): void
    {
        $this->writeLine($message, 'info');
    }

    private function writeError(string $message): void
    {
        $this->writeLine($message, 'error');
    }

    private function writeLine(string $message, ?string $style = null): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $message) ?: [''] as $line) {
            $this->line(sprintf('[%s] %s', now()->format('Y-m-d H:i:s'), $line), $style);
        }
    }
}
