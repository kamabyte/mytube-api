<?php

namespace App\Http\Controllers\Web;

use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Models\Channel;
use App\Models\Video;
use App\Support\Youtube\WatchLater;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WatchLaterController
{
    public function __invoke(): Response
    {
        $source = Channel::where('external_id', Channel::WATCH_LATER_ID)->first();
        $enabled = (bool) config('services.youtube.watch_later_enabled');

        $videos = Video::catalog()
            ->whereHas('channels', fn ($query) => $query->whereKey($source?->id))
            ->withDownloadState()
            ->with(ChannelCard::VIDEO_CHANNEL)
            ->orderByDesc('videos.id')
            ->paginate(24);

        return Inertia::render('watch-later', [
            'enabled' => $enabled,
            'lastSyncedAt' => $source?->last_synced_at,
            'syncError' => $enabled ? Cache::get(WatchLater::STATUS_KEY) : null,
            'videos' => Inertia::scroll(VideoCard::collection($videos)),
        ]);
    }
}
