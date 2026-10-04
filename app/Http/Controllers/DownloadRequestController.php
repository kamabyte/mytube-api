<?php

namespace App\Http\Controllers;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\DownloadRequests;
use App\Support\VideoUnavailable;
use Illuminate\Http\JsonResponse;

/**
 * Запрос на скачивание из ТВ-клиентов: то же, что кнопка «Скачать в медиатеку»
 * в вебе. В ответ — видео со свежим download_state, его же клиент переспрашивает
 * через GET /api/videos/{id}, пока видео не скачается.
 */
class DownloadRequestController extends Controller
{
    public function store(Video $catalogVideo, DownloadRequests $requests): VideoResource|JsonResponse
    {
        try {
            $requests->request($catalogVideo);
        } catch (VideoUnavailable) {
            return response()->json(['message' => 'The video is unavailable on YouTube and cannot be downloaded.'], 422);
        }

        $video = $this->fresh($catalogVideo);

        return new VideoResource($video);
    }

    public function destroy(Video $catalogVideo, DownloadRequests $requests): VideoResource
    {
        $requests->cancel($catalogVideo);

        $video = $this->fresh($catalogVideo);

        return new VideoResource($video);
    }

    /** Заново — с состоянием загрузки, которое считается подзапросами. */
    private function fresh(Video $video): Video
    {
        return Video::catalog()
            ->withDownloadState()
            ->with(VideoResource::CHANNEL_RELATION)
            ->findOrFail($video->id);
    }
}
