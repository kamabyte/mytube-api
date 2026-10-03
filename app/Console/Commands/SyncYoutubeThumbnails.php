<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Video;
use App\Support\StoresPublicThumbnail;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncYoutubeThumbnails extends Command
{
    protected $signature = 'youtube:sync-thumbnails {--channels : Sync only channels} {--videos : Sync only videos} {--force : Re-download already local thumbnails}';

    protected $description = 'Download and store youtube channel and video thumbnails on the public disk';

    public function handle(StoresPublicThumbnail $thumbnailStore): int
    {
        $syncChannels = (bool) $this->option('channels');
        $syncVideos = (bool) $this->option('videos');

        if (! $syncChannels && ! $syncVideos) {
            $syncChannels = true;
            $syncVideos = true;
        }

        if ($syncChannels) {
            $this->syncChannels($thumbnailStore, (bool) $this->option('force'));
        }

        if ($syncVideos) {
            $this->syncVideos($thumbnailStore, (bool) $this->option('force'));
        }

        return self::SUCCESS;
    }

    private function syncChannels(StoresPublicThumbnail $thumbnailStore, bool $force): void
    {
        $processed = 0;
        $updated = 0;

        Channel::query()
            ->whereNotNull('thumbnail')
            ->orderBy('id')
            ->chunkById(100, function ($channels) use ($thumbnailStore, $force, &$processed, &$updated): void {
                foreach ($channels as $channel) {
                    $processed++;
                    $currentThumbnail = $channel->getRawOriginal('thumbnail');

                    if (! $force && ! Str::isUrl($currentThumbnail)) {
                        continue;
                    }

                    $thumbnail = $thumbnailStore->replaceFromUrl(
                        Str::isUrl($currentThumbnail) ? $currentThumbnail : $channel->thumbnail,
                        $currentThumbnail,
                        'thumbnails/channels',
                        $channel->external_id,
                    );

                    if ($thumbnail !== $currentThumbnail) {
                        $channel->forceFill(['thumbnail' => $thumbnail])->save();
                        $updated++;
                    }
                }
            });

        $this->info("Channels processed: {$processed}; updated: {$updated}");
    }

    private function syncVideos(StoresPublicThumbnail $thumbnailStore, bool $force): void
    {
        $processed = 0;
        $updated = 0;

        Video::withoutGlobalScopes()
            ->whereNotNull('thumbnail')
            ->orderBy('id')
            ->chunkById(100, function ($videos) use ($thumbnailStore, $force, &$processed, &$updated): void {
                foreach ($videos as $video) {
                    $processed++;
                    $currentThumbnail = $video->getRawOriginal('thumbnail');

                    if (! $force && ! Str::isUrl($currentThumbnail)) {
                        continue;
                    }

                    $thumbnail = $thumbnailStore->replaceFromUrl(
                        Str::isUrl($currentThumbnail) ? $currentThumbnail : $video->thumbnail,
                        $currentThumbnail,
                        'thumbnails/videos',
                        $video->external_id,
                    );

                    if ($thumbnail !== $currentThumbnail) {
                        $video->forceFill(['thumbnail' => $thumbnail])->save();
                        $updated++;
                    }
                }
            });

        $this->info("Videos processed: {$processed}; updated: {$updated}");
    }
}
