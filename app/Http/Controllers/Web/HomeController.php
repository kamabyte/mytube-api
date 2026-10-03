<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Models\Channel;
use App\Models\Video;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const int FEATURED_SIZE = 6;

    private const int SHELF_SIZE = 16;

    public function __invoke(): Response
    {
        // Витрина вверху — последние скачанные.
        $featured = Video::query()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->orderByRaw('downloaded_at IS NULL')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('published_at')
            ->limit(self::FEATURED_SIZE)
            ->get();

        $latest = Video::query()
            ->with('channel:id,name,thumbnail,is_playlist')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::SHELF_SIZE)
            ->get();

        // Полка на канал, каналы — по свежести последнего видео.
        $shelves = Channel::query()
            ->has('videos')
            ->withMax('videos', 'published_at')
            ->withCount('videos')
            ->with(['videos' => fn ($query) => $query
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
