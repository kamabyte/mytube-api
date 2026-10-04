<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Support\DownloadState;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;

/**
 * Запрос на скачивание видео из каталога. Воркер берёт запрошенные первыми,
 * в порядке запросов (mytube-workers, DbJobSource).
 */
class DownloadRequestController extends Controller
{
    public function store(Video $catalogVideo): RedirectResponse
    {
        $state = DownloadState::of($catalogVideo);

        if ($state === DownloadState::UNAVAILABLE) {
            Toast::error('Это видео не скачать', 'YouTube его не отдаёт: удалено, закрыто или недоступно в регионе.');

            return back();
        }

        if ($state === DownloadState::DOWNLOADED) {
            return back();
        }

        // Повторный запрос не отодвигает видео в конец очереди.
        $catalogVideo->download_requested_at ??= now();
        $catalogVideo->save();

        Toast::success('Видео в очереди на загрузку', 'Час видео скачивается за 3–4 минуты, страница обновится сама.');

        return back();
    }

    /**
     * Отменяет запрос. Начатую загрузку воркер доведёт до конца, а видео
     * канала с автоскачиванием останется в очереди и так.
     */
    public function destroy(Video $catalogVideo): RedirectResponse
    {
        if (! $catalogVideo->is_downloaded && $catalogVideo->download_requested_at) {
            $catalogVideo->download_requested_at = null;
            $catalogVideo->save();

            Toast::success('Загрузка отменена');
        }

        return back();
    }
}
