<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\VideoCard;
use App\Models\Video;
use App\Models\VideoDownloadRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DownloadController extends Controller
{
    private const int PAGE_SIZE = 20;

    private const int RETRY_MINUTES = 15;

    public function index(Request $request): JsonResponse|Response
    {
        $snapshot = fn () => DB::transaction(fn () => $this->queue($request->integer('page', 1)));

        return $request->wantsJson()
            ? response()->json($snapshot())
            : Inertia::render('downloads', ['snapshot' => $snapshot]);
    }

    private function queue(int $page): array
    {
        $run = VideoDownloadRun::query()->running()->latest('started_at')->latest('id')->first();
        $current = $run
            ? Video::catalog()->where('is_downloaded', false)->where('is_unavailable', false)
                ->withDownloadState()->with('channel')->find($run->video_id)
            : null;

        $pending = Video::catalog()->awaitingDownload()
            ->when($current, fn ($query) => $query->whereKeyNot($current->id));

        $count = (clone $pending)->count();
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        // DbJobSource leaves a failed video queued but skips it for 15 minutes.
        $queue = $pending->withDownloadState()->with('channel')
            ->withMax(['downloadRuns as last_failure_at' => fn (Builder $query) => $query
                ->where('status', 'failed')->where('finished_at', '>', now('UTC')->subMinutes(self::RETRY_MINUTES))], 'finished_at')
            ->orderBy('last_failure_at')->inDownloadOrder()->forPage($page, self::PAGE_SIZE)->get();

        return [
            'current' => $current ? (new VideoCard($current))->resolve() : null,
            'started_at' => $current ? $run->started_at : null,
            'queue' => $queue->map(fn (Video $video) => [
                ...(new VideoCard($video))->resolve(),
                'retry_pending' => $video->last_failure_at !== null,
                'retry_at' => $video->last_failure_at
                    ? CarbonImmutable::parse($video->last_failure_at, 'UTC')->addMinutes(self::RETRY_MINUTES)->toISOString()
                    : null,
            ])->all(),
            'queued_count' => $count,
            'page' => $page,
            'pages' => $pages,
        ];
    }
}
