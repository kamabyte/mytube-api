<?php

namespace App\Http\Resources;

use App\Models\Video;
use App\Support\DownloadState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * Колонки для списков ТВ-клиента (главная, поиск, «Далее»):
     * без description, чтобы не раздувать ответ.
     */
    /**
     * С именем таблицы: через $channel->videos() запрос соединяется
     * с channel_video, где тоже есть channel_id.
     */
    public const array LIST_COLUMNS = [
        'videos.id',
        'videos.channel_id',
        'videos.name',
        'videos.thumbnail',
        'videos.duration_seconds',
        'videos.view_count',
        'videos.created_at',
        'videos.updated_at',
        'videos.published_at',
        'videos.downloaded_at',
    ];

    /** Канал, вложенный в каждое видео. */
    public const string CHANNEL_RELATION = 'channel:id,name,thumbnail,is_playlist';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id', $this->id),
            'channel_id' => $this->whenHas('channel_id', $this->channel_id),
            'name' => $this->whenHas('name', $this->name),
            'description' => $this->whenHas('description', $this->description),
            'thumbnail' => $this->whenHas('thumbnail', $this->thumbnail),
            'duration_seconds' => $this->whenHas('duration_seconds', $this->duration_seconds),
            'view_count' => $this->whenHas('view_count', $this->view_count),
            // У нескачанного видео каталога потока нет. is_downloaded может и не быть
            // среди выбранных колонок (LIST_COLUMNS) — тогда это скачанное.
            'video_url' => $this->whenHas('id', fn () => $this->resource->getAttribute('is_downloaded') === false
                ? null
                : route('api.videos.stream', $this->resource)),
            'download_state' => $this->whenHas('is_downloaded', fn () => DownloadState::of($this->resource)),
            'download_requested_at' => $this->whenHas('download_requested_at', $this->download_requested_at),
            'created_at' => $this->whenHas('created_at', $this->created_at),
            'updated_at' => $this->whenHas('updated_at', $this->updated_at),
            'published_at' => $this->whenHas('published_at', $this->published_at),
            'downloaded_at' => $this->whenHas('downloaded_at', $this->downloaded_at),
            'channel' => new ChannelResource($this->whenLoaded('channel')),
        ];
    }
}
