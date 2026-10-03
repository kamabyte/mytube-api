<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Http\Resources\VideoResource;
use App\Models\Channel;
use App\Models\Video;
use App\Support\TitleSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    private const int CHANNELS_LIMIT = 12;

    /**
     * Поиск для ТВ-клиентов: как веб (регистронезависимо, с кириллицей),
     * но запрос длиннее TitleSearch::MAX_LENGTH не обрезается, а даёт 422.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:'.TitleSearch::MAX_LENGTH],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));

        $videos = Video::query()
            ->select(VideoResource::LIST_COLUMNS)
            ->with(VideoResource::CHANNEL_RELATION)
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $channels = collect();

        if ($query === '') {
            $videos->whereRaw('1 = 0');
        } else {
            TitleSearch::apply($videos, $query);

            $channels = TitleSearch::apply(Channel::query(), $query)
                ->select('id', 'name', 'thumbnail', 'is_playlist')
                ->withCount('videos')
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::CHANNELS_LIMIT)
                ->get();
        }

        return VideoResource::collection($videos->jsonPaginate())->additional([
            'query' => $query,
            'channels' => ChannelResource::collection($channels)->resolve($request),
        ]);
    }
}
