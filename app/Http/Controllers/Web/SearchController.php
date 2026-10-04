<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Web\ChannelCard;
use App\Http\Resources\Web\VideoCard;
use App\Models\Channel;
use App\Models\Video;
use App\Support\TitleSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    private const int PER_PAGE = 24;

    private const int CHANNELS_LIMIT = 12;

    public function __invoke(Request $request): Response
    {
        $query = trim($request->string('q')->limit(TitleSearch::MAX_LENGTH, '')->value());

        if ($query === '') {
            return Inertia::render('search', [
                'query' => '',
                'channels' => [],
                'videos' => null,
            ]);
        }

        // Ищем по всему каталогу: найденное, но не скачанное можно тут же попросить.
        $videos = TitleSearch::apply(Video::catalog()->withDownloadState(), $query)
            ->with('channel:id,name,thumbnail,is_playlist')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Лениво: при подгрузке следующих страниц видео каналы не нужны.
        $channels = fn (): array => ChannelCard::collection(
            TitleSearch::apply(Channel::query(), $query)
                ->withCount(['videos', ...Channel::catalogCount()])
                ->orderBy('name')
                ->limit(self::CHANNELS_LIMIT)
                ->get()
        )->resolve();

        return Inertia::render('search', [
            'query' => $query,
            'channels' => $channels,
            'videos' => Inertia::scroll(VideoCard::collection($videos)),
        ]);
    }
}
