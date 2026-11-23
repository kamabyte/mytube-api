<?php

namespace App\Http\Resources\Web;

use App\Models\Video;
use App\Support\DownloadState;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Видео в списках веб-клиента: всё, что нужно карточке, без описания.
 *
 * @mixin Video
 */
class VideoCard extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'name' => $this->name,
            'thumbnail' => MediaUrl::public($this->getRawOriginal('thumbnail')),
            'duration_seconds' => $this->duration_seconds,
            'view_count' => $this->view_count,
            'published_at' => $this->published_at,
            'downloaded_at' => $this->downloaded_at,
            'download_state' => DownloadState::of($this->resource),
            'download_requested_at' => $this->download_requested_at,
            'download_on_demand' => $this->download_on_demand,
            'auto_download' => (bool) $this->auto_download,
            // resolve(): иначе Inertia развернёт вложенный ресурс как ответ,
            // с обёрткой {data: ...}.
            'channel' => $this->whenLoaded('channel', fn () => (new ChannelCard($this->channel))->resolve()),
        ];
    }
}
