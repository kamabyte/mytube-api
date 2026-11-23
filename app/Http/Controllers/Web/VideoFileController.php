<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Support\DeletePin;
use App\Support\HumanBytes;
use App\Support\LibraryCleaner;
use App\Support\MediaUnavailable;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Файл скачанного видео. Удаление освобождает место, а видео остаётся в каталоге:
 * чтобы посмотреть, его снова просят скачать.
 */
class VideoFileController extends Controller
{
    public function destroy(Request $request, Video $catalogVideo, LibraryCleaner $cleaner, DeletePin $deletePin): RedirectResponse
    {
        $deletePin->authorize($request);

        try {
            $removal = $cleaner->unloadVideo($catalogVideo);
        } catch (MediaUnavailable) {
            throw ValidationException::withMessages([
                'file' => 'Медиадиск недоступен. Попробуйте выгрузить видео позже.',
            ]);
        }

        if ($removal->hasLeftovers()) {
            throw ValidationException::withMessages([
                'file' => 'Не удалось выгрузить все файлы видео. Проверьте права записи на медиадиск.',
            ]);
        }

        $freed = $removal->freedBytes > 0 ? 'Освобождено '.HumanBytes::format($removal->freedBytes).'. ' : '';

        Toast::success("«{$catalogVideo->name}» выгружено", $freed.'Видео можно снова скачать вручную из каталога.');

        return back();
    }
}
