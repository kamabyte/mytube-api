<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Models\Channel;
use App\Models\Scopes\DownloadedVideo;
use App\Models\Video;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const int FEATURED_SIZE = 6;

    private const int SHELF_SIZE = 16;

    public function __invoke(): Response
    {
        // Витрина вверху — последние скачанные: её смотрят сразу.
        $featured = Video::query()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->orderByRaw('downloaded_at IS NULL')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('published_at')
            ->limit(self::FEATURED_SIZE)
            ->get();

        // Дальше — весь каталог: и скачанное, и то, что можно попросить (с бейджами).
        $latest = Video::catalog()
            ->withDownloadState()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::SHELF_SIZE)
            ->get();

        // Полка на канал, каналы — по свежести последнего видео каталога.
        $shelves = Channel::query()
            ->whereHas('videos', Channel::catalogVideos()['videos'])
            ->withMax(Channel::catalogVideos(), 'published_at')
            ->withCount(['videos', ...Channel::catalogCount()])
            ->with(['videos' => fn ($query) => $query
                ->withoutGlobalScope(DownloadedVideo::class)
                ->whereNull('videos.removed_at')
                ->withDownloadState()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(self::SHELF_SIZE)])
            ->orderByDesc('videos_max_published_at')
            ->get();

        return Inertia::render('home', [
            'featured' => VideoCard::collection($featured)->resolve(),
            'latest' => VideoCard::collection($latest)->resolve(),
            'shelves' => ChannelCard::collection($shelves)->resolve(),
        ]);
    }
}
