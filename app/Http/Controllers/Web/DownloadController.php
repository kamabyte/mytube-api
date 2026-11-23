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
            ? Video::catalog()->where('is_downloaded', false)->where('is_unavailable', false)
                ->withDownloadState()->with('channel')->find($run->video_id)
            : null;

        $pending = Video::catalog()
            ->awaitingDownload()
            ->when($current, fn ($query) => $query->whereKeyNot($current->id));

        $queuedCount = (clone $pending)->count();
        $queue = $pending
            ->withDownloadState()
            ->with('channel')
            ->inDownloadOrder()
            ->limit(self::QUEUE_LIMIT)
            ->get();

        return response()->json([
            'current' => $current ? (new VideoCard($current))->resolve() : null,
            'started_at' => $current ? $run->started_at : null,
            'queue' => VideoCard::collection($queue)->resolve(),
            'queued_count' => $queuedCount,
        ]);
    }
}
