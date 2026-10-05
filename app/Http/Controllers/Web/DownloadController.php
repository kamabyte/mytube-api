<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\VideoCard;
use App\Models\Video;
use App\Models\VideoDownloadRun;
use Illuminate\Http\JsonResponse;

/**
 * Панель «Загрузки» в шапке: что воркер качает сейчас и что ждёт —
 * в том порядке, в каком он это возьмёт. Процентов воркер не сообщает,
 * поэтому у текущей загрузки только время старта.
 */
class DownloadController extends Controller
{
    private const int QUEUE_LIMIT = 20;

    public function index(): JsonResponse
    {
        $run = VideoDownloadRun::query()->running()->latest('started_at')->first();
        $current = $run
            ? Video::catalog()->awaitingDownload()->withDownloadState()->with('channel')->find($run->video_id)
            : null;

        $queue = Video::catalog()
            ->awaitingDownload()
            ->withDownloadState()
            ->with('channel')
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->inDownloadOrder()
            ->limit(self::QUEUE_LIMIT)
            ->get();
        $queuedCount = Video::catalog()->awaitingDownload()->count() - ($current ? 1 : 0);

        return response()->json([
            'current' => $current ? (new VideoCard($current))->resolve() : null,
            'started_at' => $current ? $run->started_at : null,
            'queue' => VideoCard::collection($queue)->resolve(),
            'queued_count' => $queuedCount,
        ]);
    }
}
