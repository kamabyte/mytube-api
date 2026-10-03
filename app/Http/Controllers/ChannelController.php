<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ChannelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $channels = QueryBuilder::for(Channel::class)
            ->allowedFields(
                'id',
                'name',
                'description',
                'thumbnail',
                'is_playlist',
                'created_at',
                'updated_at',
                'last_synced_at',
                'recent_videos.id',
                'recent_videos.channel_id',
                'recent_videos.name',
                'recent_videos.thumbnail',
                'recent_videos.duration_seconds',
                'recent_videos.published_at',
            )
            ->allowedSorts(
                // id — чтобы порядок был стабильным между страницами.
                AllowedSort::callback('name', function ($query, bool $descending) {
                    $direction = $descending ? 'desc' : 'asc';
                    $query->orderBy('name', $direction)->orderBy('id', $direction);
                }),
                AllowedSort::field('created_at'),
                AllowedSort::callback('latest_video_published_at', function ($query, bool $descending) {
                    $query
                        ->withMax('videos', 'published_at')
                        ->orderByRaw('videos_max_published_at IS NULL')
                        ->orderBy('videos_max_published_at', $descending ? 'desc' : 'asc');
                }),
            )
            ->allowedIncludes('videos', 'recentVideos')
            ->allowedFilters(
                AllowedFilter::callback('has_videos', function ($query, $value) {
                    $query->when($value, fn ($query) => $query->has('videos'));
                }),
                AllowedFilter::callback('is_playlist', function ($query, $value) {
                    $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                    if ($flag !== null) {
                        $query->where('is_playlist', $flag);
                    }
                }),
            )
            // Только скачанные видео — через глобальный скоуп DownloadedVideo.
            // После allowedFields: иначе select() из fields[] затрёт подзапрос.
            ->withCount('videos')
            ->defaultSort('-created_at')
            ->jsonPaginate();

        return ChannelResource::collection($channels);
    }

    public function show(Channel $channel): ChannelResource
    {
        $channel->loadCount('videos');

        return new ChannelResource($channel);
    }
}
