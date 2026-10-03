<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Удаление из архива: канала целиком или отдельного видео — вместе с файлами
 * на медиадиске и обложками. Общее для youtube:remove-channel и веб-клиента.
 *
 * Скачанное видео лежит в videos/<channel_id>/<video_id>.<ext> на диске media
 * (см. VideoFile). Видео может быть сразу в нескольких каналах и плейлистах:
 * вместе с источником удаляется только то, чего больше нигде нет.
 */
class LibraryCleaner
{
    public function isMediaDiskAvailable(): bool
    {
        $root = config('filesystems.disks.media.root');

        return is_string($root) && trim($root) !== '' && is_dir($root);
    }

    /**
     * Что будет удалено вместе с каналом. Видео, которые есть и в других
     * источниках, остаются там (videos_shared) и в подсчёт не входят.
     *
     * @return array{videos_total: int, videos_downloaded: int, videos_shared: int, thumbnails: Collection<int, string>, media_directory: string, media_available: bool, media_files: list<string>, media_bytes: int}
     */
    public function channelSummary(Channel $channel): array
    {
        $videos = fn () => $this->exclusiveVideos($channel);

        $thumbnails = $videos()
            ->whereNotNull('thumbnail')
            ->toBase()
            ->pluck('thumbnail')
            ->push($channel->getRawOriginal('thumbnail'))
            ->filter(fn (?string $path) => filled($path) && ! Str::isUrl($path))
            ->unique()
            ->values();

        $mediaDirectory = "videos/{$channel->id}";
        $mediaAvailable = $this->isMediaDiskAvailable();
        $mediaFiles = [];
        $mediaBytes = 0;

        if ($mediaAvailable) {
            $disk = Storage::disk('media');
            $mediaFiles = $videos()
                ->where('is_downloaded', true)
                ->get(['id', 'channel_id'])
                ->flatMap(fn (Video $video) => $this->mediaFiles($video))
                ->values()
                ->all();

            foreach ($mediaFiles as $file) {
                $mediaBytes += (int) $disk->size($file);
            }
        }

        return [
            'videos_total' => $videos()->count(),
            'videos_downloaded' => $videos()->where('is_downloaded', true)->count(),
            'videos_shared' => $this->sharedVideos($channel)->count(),
            'thumbnails' => $thumbnails,
            'media_directory' => $mediaDirectory,
            'media_available' => $mediaAvailable,
            'media_files' => $mediaFiles,
            'media_bytes' => $mediaBytes,
        ];
    }

    /**
     * Удаляет канал, его видео, обложки и файлы. Видео, которые есть ещё
     * где-то, только отвязываются от канала. Записи в базе удаляются всегда;
     * что из файлов удалить не вышло — в ответе.
     *
     * @param  array{thumbnails: Collection<int, string>, media_directory: string, media_available: bool, media_files: list<string>}  $summary
     */
    public function removeChannel(Channel $channel, array $summary): ChannelRemoval
    {
        DB::transaction(function () use ($channel): void {
            $this->sharedVideos($channel)
                ->get()
                ->each(fn (Video $video) => $this->detach($video, $channel));

            $this->exclusiveVideos($channel)->delete();
            $channel->delete();
        });

        $this->deleteThumbnails($summary['thumbnails']);

        $keptMedia = false;

        if ($summary['media_available']) {
            $disk = Storage::disk('media');
            $disk->delete($summary['media_files']);
            $keptMedia = collect($summary['media_files'])->contains(fn (string $file) => $disk->exists($file));

            // В каталоге могут остаться файлы видео, которые живут дальше
            // в других источниках, — такой каталог не трогаем.
            if ($disk->directoryExists($summary['media_directory']) && $disk->allFiles($summary['media_directory']) === []) {
                $disk->deleteDirectory($summary['media_directory']);
            }
        }

        $keptThumbnails = $summary['thumbnails']
            ->filter(fn (string $path) => Storage::disk('public')->exists($path))
            ->values();

        return new ChannelRemoval($keptMedia, $keptThumbnails);
    }

    /**
     * Видео пропали из плейлиста на YouTube. Отвязываем их от плейлиста;
     * те, что больше нигде не лежат, удаляем вместе с файлом и обложкой,
     * чтобы при возвращении в плейлист они скачались заново.
     *
     * Удалённые вручную (removed_at) остаются строкой-меткой. Без медиадиска
     * скачанные сироты не трогаем, иначе их файлы остались бы без записи.
     *
     * @param  iterable<int, Video>  $videos
     * @return array{detached: int, purged: int}
     */
    public function detachVideos(Channel $channel, iterable $videos): array
    {
        $detached = 0;
        $purged = 0;
        $mediaAvailable = $this->isMediaDiskAvailable();

        foreach ($videos as $video) {
            $isOrphan = ! $video->channels()->whereKeyNot($channel->id)->exists();

            if (! $isOrphan || $video->removed_at !== null) {
                $this->detach($video, $channel);
                $detached++;

                continue;
            }

            if ($video->is_downloaded && ! $mediaAvailable) {
                continue;
            }

            if ($mediaAvailable) {
                Storage::disk('media')->delete($this->mediaFiles($video));
            }

            $this->deleteThumbnails(collect([$video->getRawOriginal('thumbnail')])
                ->filter(fn (?string $path) => filled($path) && ! Str::isUrl($path)));

            $video->delete();
            $purged++;
        }

        return ['detached' => $detached, 'purged' => $purged];
    }

    /**
     * Удаляет скачанный файл и обложку видео, но не саму строку: по ней парсер
     * узнаёт видео и не заводит его заново. is_unavailable убирает его из
     * очереди воркера, removed_at — из-под парсера.
     *
     * @throws MediaUnavailable Иначе запись пропала бы, а файл остался
     *                          занимать место.
     */
    public function removeVideo(Video $video): VideoRemoval
    {
        $freed = 0;

        if (! $this->isMediaDiskAvailable()) {
            throw new MediaUnavailable(sprintf(
                'The media disk (%s) is not available, the video file cannot be removed.',
                config('filesystems.disks.media.root') ?: 'MEDIA_ROOT is not set',
            ));
        }

        $disk = Storage::disk('media');
        $files = $this->mediaFiles($video);

        foreach ($files as $file) {
            $freed += (int) $disk->size($file);
        }

        $disk->delete($files);

        $thumbnail = $video->getRawOriginal('thumbnail');

        if ($thumbnail && ! Str::isUrl($thumbnail)) {
            Storage::disk('public')->delete($thumbnail);
        }

        $video->forceFill([
            'is_downloaded' => false,
            'is_unavailable' => true,
            'removed_at' => now(),
            'downloaded_at' => null,
            'file_size' => null,
            'thumbnail' => null,
        ])->save();

        $kept = array_values(array_filter($files, fn (string $file) => $disk->exists($file)));

        return new VideoRemoval(
            freedBytes: $kept === [] ? $freed : 0,
            keptFiles: array_map(fn (string $file) => $disk->path($file), $kept),
        );
    }

    /**
     * Отвязывает видео от канала. Если канал был основным источником,
     * основным становится другой — канал автора раньше плейлистов.
     */
    private function detach(Video $video, Channel $channel): void
    {
        $video->channels()->detach($channel->id);

        if ($video->channel_id !== $channel->id) {
            return;
        }

        $next = $video->channels()
            ->orderBy('is_playlist')
            ->orderBy('channels.id')
            ->first();

        if ($next) {
            $video->forceFill(['channel_id' => $next->id])->save();
        }
    }

    /**
     * Видео канала, которых нет ни в одном другом источнике.
     *
     * @return Builder<Video>
     */
    private function exclusiveVideos(Channel $channel): Builder
    {
        return $this->channelVideos($channel)
            ->whereDoesntHave('channels', fn (Builder $query) => $query->whereKeyNot($channel->id));
    }

    /**
     * Видео канала, которые лежат и в других источниках.
     *
     * @return Builder<Video>
     */
    private function sharedVideos(Channel $channel): Builder
    {
        return $this->channelVideos($channel)
            ->whereHas('channels', fn (Builder $query) => $query->whereKeyNot($channel->id));
    }

    /**
     * @return Builder<Video>
     */
    private function channelVideos(Channel $channel): Builder
    {
        return Video::withoutGlobalScopes()->where(fn (Builder $query) => $query
            ->where('channel_id', $channel->id)
            ->orWhereHas('channels', fn (Builder $query) => $query->whereKey($channel->id)));
    }

    /**
     * Файлы видео на медиадиске, пути относительно его корня.
     *
     * @return list<string>
     */
    private function mediaFiles(Video $video): array
    {
        $root = rtrim(Storage::disk('media')->path(''), DIRECTORY_SEPARATOR);

        return array_map(
            fn (string $path) => ltrim(Str::after($path, $root), DIRECTORY_SEPARATOR),
            VideoFile::paths($video),
        );
    }

    /**
     * @param  Collection<int, string>  $thumbnails
     */
    private function deleteThumbnails(Collection $thumbnails): void
    {
        $thumbnails
            ->chunk(100)
            ->each(fn (Collection $chunk) => Storage::disk('public')->delete($chunk->values()->all()));
    }
}
