<?php

namespace App\Support;

use App\Models\Video;

/**
 * Где видео на пути в медиатеку. Для очереди нужен Video::withDownloadState(),
 * иначе каталожное видео канала с автоскачиванием выглядит как «можно скачать».
 */
class DownloadState
{
    /** В каталоге, никто не просил. */
    public const string AVAILABLE = 'available';

    /** Ждёт воркера: попросили или канал качает всё сам. */
    public const string QUEUED = 'queued';

    public const string DOWNLOADING = 'downloading';

    public const string DOWNLOADED = 'downloaded';

    /** Не скачать: удалено с YouTube, приватное и т.п. */
    public const string UNAVAILABLE = 'unavailable';

    public static function of(Video $video): string
    {
        if ($video->is_downloaded) {
            return self::DOWNLOADED;
        }

        if ($video->is_unavailable) {
            return self::UNAVAILABLE;
        }

        if ($video->downloading) {
            return self::DOWNLOADING;
        }

        if ($video->download_requested_at || $video->auto_download) {
            return self::QUEUED;
        }

        return self::AVAILABLE;
    }
}
