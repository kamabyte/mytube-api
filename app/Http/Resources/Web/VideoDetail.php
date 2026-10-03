<?php

namespace App\Http\Resources\Web;

use App\Models\Video;
use App\Support\Subtitles;
use App\Support\VideoFile;
use Illuminate\Http\Request;

/**
 * Видео для страницы просмотра: карточка плюс описание и поток.
 *
 * @mixin Video
 */
class VideoDetail extends VideoCard
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'external_id' => $this->external_id,
            'description' => $this->description,
            'file_size' => $this->file_size,
            // Относительный адрес: поток отдаёт nginx того же хоста,
            // через который открыта страница.
            'stream_url' => route('api.videos.stream', $this->resource, absolute: false),
            'subtitles' => $this->subtitles(),
        ];
    }

    /**
     * @return list<array{track: int, language: string|null, label: string, url: string}>
     */
    private function subtitles(): array
    {
        $path = VideoFile::path($this->resource);

        if ($path === null) {
            return [];
        }

        $subtitles = app(Subtitles::class);
        $version = $subtitles->version($path);

        return array_map(fn (array $track) => [
            ...$track,
            'url' => route('api.videos.subtitles', [$this->resource, $track['track'], 'v' => $version], absolute: false),
        ], $subtitles->tracks($path));
    }
}
