<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Support\DeletePin;
use App\Support\HumanBytes;
use App\Support\LibraryCleaner;
use App\Support\MediaUnavailable;
use App\Support\Toast;
use App\Support\UnloadWouldRequeue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Файл скачанного видео. Удаление освобождает место, а видео остаётся в каталоге:
 * чтобы посмотреть, его снова просят скачать.
 */
class VideoFileController extends Controller
{
    public function destroy(Request $request, Video $video, LibraryCleaner $cleaner, DeletePin $deletePin): RedirectResponse
    {
        $deletePin->authorize($request);

        try {
            $removal = $cleaner->unloadVideo($video);
        } catch (MediaUnavailable) {
            Toast::error('Медиадиск недоступен — файл не удалён');

            return back();
        } catch (UnloadWouldRequeue) {
            // Ошибкой формы, а не всплывашкой: диалог останется открытым и покажет её.
            throw ValidationException::withMessages([
                'file' => 'Канал скачивается целиком — файл скачался бы снова. Переключите канал на «Скачивать только по запросу».',
            ]);
        }

        if ($removal->hasLeftovers()) {
            Toast::error(
                'Видео вернулось в каталог, но файл удалить не удалось',
                'Проверьте права на медиадиск — подробности в логах сервиса в Dokploy.',
            );

            return back();
        }

        $freed = $removal->freedBytes > 0 ? 'Освобождено '.HumanBytes::format($removal->freedBytes).'. ' : '';

        Toast::success("Файл «{$video->name}» удалён", $freed.'Видео осталось в каталоге — его можно скачать снова.');

        return back();
    }
}
