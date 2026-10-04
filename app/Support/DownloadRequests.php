<?php

namespace App\Support;

use App\Models\Video;

/**
 * Запросы на скачивание видео из каталога — общие для веба и JSON API ТВ-клиентов.
 * Воркер берёт запрошенные первыми, в порядке запросов (mytube-workers, DbJobSource).
 */
class DownloadRequests
{
    /**
     * Ставит видео в очередь. Повторный запрос не отодвигает его в конец,
     * скачанное не трогает.
     *
     * @throws VideoUnavailable
     */
    public function request(Video $video): void
    {
        if (DownloadState::of($video) === DownloadState::UNAVAILABLE) {
            throw new VideoUnavailable;
        }

        if ($video->is_downloaded || $video->download_requested_at) {
            return;
        }

        $video->download_requested_at = now();
        $video->save();
    }

    /**
     * Снимает запрос. Начатую загрузку воркер доведёт до конца, а видео
     * канала с автоскачиванием останется в очереди и так.
     *
     * @return bool false — снимать было нечего
     */
    public function cancel(Video $video): bool
    {
        if ($video->is_downloaded || ! $video->download_requested_at) {
            return false;
        }

        $video->download_requested_at = null;
        $video->save();

        return true;
    }
}
