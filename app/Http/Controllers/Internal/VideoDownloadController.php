<?php

namespace App\Http\Controllers\Internal;

use App\Events\VideoDownloaded;
use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Хук воркера (mytube-workers, HttpDownloadNotifier): видео скачалось.
 * Воркер пишет в базу сам, а Laravel узнаёт об этом отсюда — и тут же
 * рассылает событие веб-клиентам через Reverb.
 */
class VideoDownloadController extends Controller
{
    public function store(Request $request, Video $video): Response
    {
        $token = (string) config('services.worker.hook_token');

        // Токен не задан — хук выключен, а не открыт всем.
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

        VideoDownloaded::dispatch($video);

        return response()->noContent();
    }
}
