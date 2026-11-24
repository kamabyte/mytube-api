<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Video;
use App\Support\LibraryCleaner;
use App\Support\StoresPublicThumbnail;
use App\Support\Youtube\UploadsFeed;
use App\Support\Youtube\VideoThumbnail;
use App\Support\Youtube\WatchLater;
use Carbon\Carbon;
use DateInterval;
use Google\Service\YouTube;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ParseYoutubeVideos extends Command
{
    protected $signature = 'youtube:parse-videos {--channel= : Parse videos only for the given channel id} {--popular : Parse popular channel videos instead of latest uploads} {--watch-later : Import videos from YouTube Watch Later}';

    protected $description = 'Парсинг новых видео с подписанных каналов';

    /**
     * Плейлист проходится через API целиком (RSS у плейлистов нет), это квота
     * на каждый прогон — поэтому по расписанию его проверяем реже, чем каналы.
     */
    private const int PLAYLIST_INTERVAL_MINUTES = 10;

    /**
     * Премьеру или трансляцию перепроверяем через столько минут после начала
     * эфира и дальше с тем же шагом, пока она не закончится.
     */
    private const int UPCOMING_RECHECK_MINUTES = 30;

    /** Эфир, который так и не начался или не закончился за это время от начала, больше не ждём. */
    private const int UPCOMING_GIVE_UP_DAYS = 7;

    public function handle(
        YouTube $youTube,
        StoresPublicThumbnail $thumbnailStore,
        LibraryCleaner $cleaner,
        UploadsFeed $feed,
        WatchLater $watchLater,
    ) {
        $channelId = $this->option('channel');
        $parsePopular = (bool) $this->option('popular');
        $syncWatchLater = (bool) $this->option('watch-later');
        $watchLaterIds = [];

        if ($syncWatchLater) {
            if ($channelId || $parsePopular || ! config('services.youtube.watch_later_enabled')) {
                $this->error('Для «Смотреть позже» включите YOUTUBE_WATCH_LATER_ENABLED и запустите --watch-later отдельно.');

                return self::FAILURE;
            }

            try {
                $watchLaterIds = $watchLater->videoIds();
            } catch (RuntimeException $e) {
                return $this->watchLaterFailed($e->getMessage());
            }

            $channels = collect([Channel::firstOrCreate(['external_id' => Channel::WATCH_LATER_ID], [
                'name' => 'Смотреть позже',
                'is_playlist' => true,
                'parse_latest' => true,
                'download_on_demand' => false,
            ])]);
        } else {
            $channels = Channel::youtubeSources()
                ->when($channelId, fn ($query) => $query->whereKey($channelId))
                ->get();
        }

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

            if ($channel->sync_paused) {
                $this->logVerbose('Обновление канала приостановлено');

                continue;
            }

            try {
                if ($parsePopular && ! $channel->parse_popular) {
                    $this->writeInfo('Парсинг популярных видео отключен для канала');

                    continue;
                }

                if (! $parsePopular && ! $channel->parse_latest) {
                    $this->writeInfo('Парсинг последних видео отключен для канала');

                    continue;
                }

                // --channel (сразу после добавления) — без паузы.
                if (! $syncWatchLater && ! $parsePopular && ! $channelId && $channel->is_playlist
                    && $channel->last_synced_at?->gt(now()->subMinutes(self::PLAYLIST_INTERVAL_MINUTES))) {
                    $this->logVerbose('Плейлист проверялся меньше '.self::PLAYLIST_INTERVAL_MINUTES.' минут назад');

                    continue;
                }

                $uploadsPlaylistId = $syncWatchLater ? null : ($channel->uploads_playlist_id ?: ($channel->is_playlist
                    ? null
                    : $this->fetchUploadsPlaylistId($youTube, $channel->external_id)));

                if (! $syncWatchLater && ! $uploadsPlaylistId) {
                    $this->writeError("Не удалось определить uploads playlist для $channel->external_id");

                    continue;
                }

                if (! $syncWatchLater && $channel->uploads_playlist_id !== $uploadsPlaylistId) {
                    $channel->update(['uploads_playlist_id' => $uploadsPlaylistId]);
                }

                $this->logVerbose("uploads playlist: $uploadsPlaylistId");

                if ($syncWatchLater) {
                    $playlistItems = [];
                    $knownIds = $channel->videos()->withoutGlobalScopes()->pluck('videos.external_id')->all();
                    $additionalVideoIds = array_diff($watchLaterIds, $knownIds);
                } elseif ($parsePopular) {
                    $playlistItems = [];
                    $popularVideos = $this->fetchPopularVideos($youTube, $uploadsPlaylistId);
                    $additionalVideoIds = collect($popularVideos)
                        ->pluck('id')
                        ->values()
                        ->all();
                    $updatedViewCounts = $this->updateExistingVideoViewCounts($channel->id, $popularVideos);
                    $this->logVerbose(sprintf(
                        'Получено популярных видео id: %d; обновлено view_count у существующих видео: %d',
                        count($additionalVideoIds),
                        $updatedViewCounts,
                    ));
                } elseif ($channel->is_playlist) {
                    // Ручной плейлист отсортирован по position, а не по дате, и видео в него
                    // добавляют задним числом: обрезать выборку по last_synced_at нельзя,
                    // поэтому проходим его целиком. Заодно видно, что из него убрали.
                    $playlistItems = $this->fetchPlaylistItems($youTube, $uploadsPlaylistId);
                    $additionalVideoIds = [];
                    $this->logVerbose('Получено playlist items (плейлист целиком): '.count($playlistItems));

                    // Для плейлиста дата в playlist item — это дата добавления, она ничего
                    // не отсекает, так что поле означает просто «когда прогоняли последний раз».
                    // Ставим сразу: и пустой плейлист не должен проверяться каждую минуту.
                    $channel->update(['last_synced_at' => now()]);
                } else {
                    // Сначала RSS: он бесплатный, а канал, где ничего не вышло, так
                    // не тратит квоту вовсе. API — когда ленты нет или в неё не влезло всё новое.
                    $feedItems = $channel->last_synced_at && UploadsFeed::covers($channel->external_id, $uploadsPlaylistId)
                        ? $this->fetchNewFeedItems($feed, $channel->external_id, $channel->last_synced_at)
                        : null;

                    if ($feedItems !== null) {
                        $playlistItems = $feedItems;
                        $this->logVerbose(sprintf(
                            'Получено новых загрузок по RSS: %d после %s',
                            count($playlistItems),
                            $channel->last_synced_at->toDateTimeString(),
                        ));
                    } elseif ($channel->last_synced_at) {
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

                    $additionalVideoIds = [];
                }

                // Премьеры, встреченные до эфира: в новых загрузках их уже нет, курсор ушёл дальше.
                $dueUpcomingIds = $parsePopular ? [] : $channel->upcomingVideos()->due()->pluck('external_id')->all();

                if (! $syncWatchLater && $playlistItems === [] && $additionalVideoIds === [] && $dueUpcomingIds === []) {
                    $this->writeInfo('Новых загрузок нет');

                    continue;
                }

                $latestPublishedAt = collect($playlistItems)
                    ->map(fn ($item) => Carbon::parse($item->snippet->publishedAt))
                    ->max();

                $videoIds = collect($playlistItems)
                    ->map(fn ($item) => $item->snippet->resourceId->videoId ?? null)
                    ->merge($additionalVideoIds)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (! $syncWatchLater && $channel->is_playlist && ! $parsePopular) {
                    $this->syncPlaylistMembership($cleaner, $channel, $videoIds);
                }

                $videoIds = array_values(array_unique([...$videoIds, ...$dueUpcomingIds]));

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

                if (! $syncWatchLater && $videoIds === []) {
                    $this->writeInfo('Видео для сохранения не найдены');

                    continue;
                }

                $savedVideosCount = 0;
                $updatedVideosCount = 0;
                $skippedShortVideosCount = 0;
                $skippedLongVideosCount = 0;
                $upcomingVideoIds = [];

                foreach (array_chunk($videoIds, 50) as $videoIdsChunk) {
                    $this->logVeryVerbose('Запрос подробностей по видео: '.implode(',', $videoIdsChunk));

                    $existingVideos = Video::withoutGlobalScopes()
                        ->with('channel:id,is_playlist')
                        ->whereIn('external_id', $videoIdsChunk)
                        ->get(['id', 'external_id', 'channel_id', 'thumbnail'])
                        ->keyBy('external_id');

                    $videosResponse = $youTube->videos->listVideos('snippet,contentDetails,statistics,liveStreamingDetails', [
                        'id' => implode(',', $videoIdsChunk),
                    ]);

                    foreach ($videosResponse->getItems() as $video) {
                        // У премьеры и трансляции до конца эфира длительность 0: без этой
                        // проверки они ушли бы в «короткие» и после эфира уже не нашлись.
                        if (in_array($video->snippet->liveBroadcastContent, ['upcoming', 'live'], true)) {
                            if ($this->awaitBroadcast($channel, $video)) {
                                $upcomingVideoIds[] = $video->id;
                            }

                            continue;
                        }

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
                        $currentThumbnail = $existingVideos->get($video->id)?->getRawOriginal('thumbnail');

                        // Обложка у видео не меняется: уже сохранённую не качаем на каждом
                        // прогоне (плейлист проходится целиком). Обновить разом —
                        // youtube:sync-thumbnails --refresh.
                        $thumbnail = $currentThumbnail && ! Str::isUrl($currentThumbnail)
                            ? $currentThumbnail
                            : $thumbnailStore->replaceFromUrls(
                                VideoThumbnail::urls($video->snippet),
                                $currentThumbnail,
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

                // Вышедшие в эфир сохранены или отсеяны по длительности, пропавшие с YouTube ждать незачем.
                $channel->upcomingVideos()
                    ->whereIn('external_id', $videoIds)
                    ->whereNotIn('external_id', $upcomingVideoIds)
                    ->delete();

                if ($syncWatchLater) {
                    $channel->update(['last_synced_at' => now()]);
                    Cache::forget(WatchLater::STATUS_KEY);
                } elseif (! $channel->is_playlist && $latestPublishedAt && (! $channel->last_synced_at || $latestPublishedAt->gt($channel->last_synced_at))) {
                    $channel->update(['last_synced_at' => $latestPublishedAt]);
                }

                $this->writeInfo("Добавлено видео: {$savedVideosCount}");
                $this->logVerbose(sprintf(
                    'Итог по каналу: обработано id=%d, создано=%d, обновлено=%d, пропущено коротких=%d, пропущено длинных=%d, ждут эфира=%d',
                    count($videoIds),
                    $savedVideosCount,
                    $updatedVideosCount,
                    $skippedShortVideosCount,
                    $skippedLongVideosCount,
                    count($upcomingVideoIds),
                ));

            } catch (\Exception $e) {
                if ($syncWatchLater) {
                    return $this->watchLaterFailed('Не удалось обновить каталог «Смотреть позже».');
                }

                $this->writeError("Ошибка для {$channel->external_id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function watchLaterFailed(string $message): int
    {
        Cache::forever(WatchLater::STATUS_KEY, $message);
        $this->error($message);

        return self::FAILURE;
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
     * Запоминает премьеру или идущую трансляцию, чтобы проверить её после эфира.
     * false — ждать больше не стоит: эфир так и не начался или идёт слишком долго.
     */
    private function awaitBroadcast(Channel $channel, YouTube\Video $video): bool
    {
        $upcoming = $channel->upcomingVideos()->firstOrNew(['external_id' => $video->id]);
        $details = $video->liveStreamingDetails;
        $startsAt = $details?->actualStartTime ?? $details?->scheduledStartTime;
        $startsAt = $startsAt ? Carbon::parse($startsAt) : ($upcoming->created_at ?? now());

        if ($startsAt->lt(now()->subDays(self::UPCOMING_GIVE_UP_DAYS))) {
            $this->logVerbose("Эфир {$video->id} не закончился за ".self::UPCOMING_GIVE_UP_DAYS." дней, больше не ждём: {$video->snippet->title}");

            return false;
        }

        $upcoming->fill(['check_after' => $startsAt->max(now())->addMinutes(self::UPCOMING_RECHECK_MINUTES)])->save();

        $this->logVeryVerbose(sprintf(
            'Ждёт эфира %s (%s, проверим после %s): %s',
            $video->id,
            $video->snippet->liveBroadcastContent,
            $upcoming->check_after->toDateTimeString(),
            $video->snippet->title,
        ));

        return true;
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

    /**
     * Новые загрузки из RSS в виде playlist items (snippet.publishedAt,
     * snippet.resourceId.videoId) — дальше они идут тем же путём, что из API.
     * null — лента недоступна или целиком новее $after: тогда новых может быть
     * больше, чем в неё влезает, и проходить загрузки надо через API.
     */
    private function fetchNewFeedItems(UploadsFeed $feed, string $channelId, Carbon $after): ?array
    {
        $uploads = $feed->latest($channelId);

        if ($uploads === null) {
            $this->logVerbose('RSS недоступен, загрузки — через API');

            return null;
        }

        $new = array_values(array_filter($uploads, fn (array $upload) => $upload['published_at']->gt($after)));

        if (count($new) >= UploadsFeed::FEED_SIZE) {
            $this->logVerbose('В RSS всё новое — могло не влезть, загрузки — через API');

            return null;
        }

        return array_map(fn (array $upload) => (object) ['snippet' => (object) [
            'publishedAt' => $upload['published_at']->toIso8601String(),
            'resourceId' => (object) ['videoId' => $upload['video_id']],
        ]], $new);
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
