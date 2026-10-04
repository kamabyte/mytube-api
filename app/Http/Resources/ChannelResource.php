<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Channel
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id', $this->id),
            'name' => $this->whenHas('name', $this->name),
            'thumbnail' => $this->whenHas('thumbnail', $this->thumbnail),
            'is_playlist' => $this->whenHas('is_playlist', fn () => (bool) $this->is_playlist),
            'download_on_demand' => $this->whenHas('download_on_demand', fn () => (bool) $this->download_on_demand),
            'videos_count' => $this->whenCounted('videos'),
            'catalog_count' => $this->whenCounted('catalog'),
            'videos' => VideoResource::collection($this->whenLoaded('videos')),
            'recent_videos' => VideoResource::collection($this->whenLoaded('recentVideos')),
        ];
    }
}
