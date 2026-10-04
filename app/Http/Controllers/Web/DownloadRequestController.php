<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Support\DownloadRequests;
use App\Support\Toast;
use App\Support\VideoUnavailable;
use Illuminate\Http\RedirectResponse;

/**
 * Запрос на скачивание видео из каталога (правила — в DownloadRequests).
 */
class DownloadRequestController extends Controller
{
    public function store(Video $catalogVideo, DownloadRequests $requests): RedirectResponse
    {
        if ($catalogVideo->is_downloaded) {
            return back();
        }

        try {
            $requests->request($catalogVideo);
        } catch (VideoUnavailable) {
            Toast::error('Это видео не скачать', 'YouTube его не отдаёт: удалено, закрыто или недоступно в регионе.');

            return back();
        }

        Toast::success('Видео в очереди на загрузку', 'Час видео скачивается за 3–4 минуты, страница обновится сама.');

        return back();
    }

    public function destroy(Video $catalogVideo, DownloadRequests $requests): RedirectResponse
    {
        if ($requests->cancel($catalogVideo)) {
            Toast::success('Загрузка отменена');
        }

        return back();
    }
}
