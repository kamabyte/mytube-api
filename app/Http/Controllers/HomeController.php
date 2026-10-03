<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Http\Resources\VideoResource;
use App\Models\Channel;
use App\Models\Video;
use App\Support\VideoSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Главная ТВ-клиента — то же, что Web\HomeController, но с ограниченными
 * полками: каждый список имеет потолок, вложенные запросы без N+1.
 */
class HomeController extends Controller
{
    private const int FEATURED_SIZE = 6;

    private const int SHELF_SIZE = 16;

    private const int CHANNELS_SIZE = 12;

    private const int CHANNEL_VIDEOS_SIZE = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $videos = fn () => Video::query()
            ->select(VideoResource::LIST_COLUMNS)
            ->with(VideoResource::CHANNEL_RELATION);

        // Витрина вверху — последние скачанные.
        $featured = $videos()
            ->orderByRaw('downloaded_at IS NULL')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::FEATURED_SIZE)
            ->get();

        $latest = VideoSort::apply($videos(), VideoSort::NEW)->limit(self::SHELF_SIZE)->get();

        $recentlyAdded = VideoSort::apply($videos(), VideoSort::ADDED)->limit(self::SHELF_SIZE)->get();

        // Полка на канал, каналы — по свежести последнего видео.
        $channels = Channel::query()
            ->select('id', 'name', 'thumbnail', 'is_playlist')
            ->has('videos')
            ->withMax('videos', 'published_at')
            ->withCount('videos')
            ->with(['videos' => fn ($query) => $query
                ->select(VideoResource::LIST_COLUMNS)
                ->with(VideoResource::CHANNEL_RELATION)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(self::CHANNEL_VIDEOS_SIZE)])
            ->orderByDesc('videos_max_published_at')
            ->orderByDesc('id')
            ->limit(self::CHANNELS_SIZE)
            ->get();

        return response()->json([
            'featured' => VideoResource::collection($featured)->resolve($request),
            'latest' => VideoResource::collection($latest)->resolve($request),
            'recently_added' => VideoResource::collection($recentlyAdded)->resolve($request),
            'channels' => ChannelResource::collection($channels)->resolve($request),
        ]);
    }
}
