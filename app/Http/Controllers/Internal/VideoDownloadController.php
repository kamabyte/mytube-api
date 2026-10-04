<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoReady;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Хук воркера (mytube-workers, HttpDownloadNotifier): видео скачалось.
 * Воркер пишет в базу сам, а Laravel узнаёт об этом отсюда — и тут же
 * сохраняет уведомление и рассылает его веб-клиентам через Reverb.
 */
class VideoDownloadController extends Controller
{
    public function store(Request $request, Video $video): Response
    {
        $token = (string) config('services.worker.hook_token');

        // Токен не задан — хук выключен, а не открыт всем.
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

        // В колокольчик и сразу в открытые вкладки (Reverb).
        User::owner()->notify(new VideoReady($video));

        return response()->noContent();
    }
}
