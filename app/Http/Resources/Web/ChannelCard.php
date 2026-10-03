<?php

namespace App\Http\Resources\Web;

use App\Models\Channel;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Канал для веб-клиента. Агрегаты (videos_count и т.п.) попадают в ответ,
 * только если их посчитали в запросе.
 *
 * @mixin Channel
 */
class ChannelCard extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'thumbnail' => MediaUrl::public($this->getRawOriginal('thumbnail')),
            'is_playlist' => (bool) $this->is_playlist,
            'videos_count' => $this->whenCounted('videos'),
            'queued_count' => $this->whenCounted('queued'),
            'total_size_bytes' => $this->whenAggregated('videos', 'file_size', 'sum', fn ($value) => (int) $value),
            'total_duration_seconds' => $this->whenAggregated('videos', 'duration_seconds', 'sum', fn ($value) => (int) $value),
            'latest_published_at' => $this->whenAggregated('videos', 'published_at', 'max'),
            // resolve(): иначе Inertia развернёт вложенный ресурс как ответ,
            // с обёрткой {data: ...}.
            'videos' => $this->whenLoaded('videos', fn () => VideoCard::collection($this->videos)->resolve()),
        ];
    }
}
