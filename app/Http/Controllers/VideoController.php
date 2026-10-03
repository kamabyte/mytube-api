<?php

namespace App\Http\Controllers;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\Subtitles;
use App\Support\UpNext;
use App\Support\VideoFile;
use App\Support\VideoSort;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class VideoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $videos = QueryBuilder::for(Video::class)
            ->allowedFields(
                'id',
                'channel_id',
                'name',
                'description',
                'thumbnail',
                'view_count',
                'created_at',
                'published_at',
                'downloaded_at',
            )
            ->allowedFilters(
                // Все видео канала или плейлиста, а не только те, где он основной.
                AllowedFilter::callback('channel_id', fn ($query, $value) => $query
                    ->whereHas('channels', fn ($query) => $query->whereKey(Arr::wrap($value)))),
            )
            ->allowedIncludes(
                'channel',
            )
            ->allowedSorts(
                AllowedSort::field('created_at'),
                AllowedSort::field('published_at'),
                AllowedSort::field('view_count'),
                // Ключи как у веба (App\Support\VideoSort), со стабильным
                // id в конце; префикс «-» для них ничего не меняет.
                ...array_map(
                    fn (string $key) => AllowedSort::callback($key, fn ($query) => VideoSort::apply($query, $key)),
                    VideoSort::ALL,
                ),
            )
            ->defaultSort('-published_at')
            ->jsonPaginate();

        return VideoResource::collection($videos);
    }

    public function show(Video $video): VideoResource
    {
        $video->load(['channel' => fn ($query) => $query
            ->select('id', 'name', 'thumbnail', 'is_playlist')
            ->withCount('videos')]);

        return new VideoResource($video);
    }

    /**
     * Очередь «Далее» — та же, что на странице просмотра в вебе.
     */
    public function upNext(Request $request, Video $video): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        return VideoResource::collection(UpNext::for(
            $video,
            (int) ($validated['limit'] ?? UpNext::DEFAULT_LIMIT),
            VideoResource::LIST_COLUMNS,
        ));
    }

    public function stream(Video $video): Response
    {
        $path = VideoFile::path($video);
        abort_if($path === null, 404, 'Video file was not found.');

        return response('', 200, [
            'Content-Type' => File::mimeType($path) ?? 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'X-Accel-Redirect' => $this->resolveInternalMediaPath($path),
        ]);
    }

    /**
     * Вшитая дорожка субтитров в WebVTT — для веб-плеера. Адрес несёт
     * отпечаток файла (?v=), поэтому ответ можно кешировать надолго.
     */
    public function subtitles(Video $video, int $track, Subtitles $subtitles): Response
    {
        $path = VideoFile::path($video);
        abort_if($path === null, 404, 'Video file was not found.');
        abort_unless(in_array($track, array_column($subtitles->tracks($path), 'track'), true), 404, 'Subtitle track was not found.');

        $vtt = $subtitles->vtt($path, $track);
        abort_if($vtt === null, 404, 'Subtitle track could not be extracted.');

        return response()->file($vtt, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function resolveInternalMediaPath(string $path): string
    {
        $mediaRoot = rtrim(Storage::disk('media')->path(''), DIRECTORY_SEPARATOR);
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', $mediaRoot);

        abort_unless(Str::startsWith($normalizedPath, $normalizedRoot.'/'), 404, 'Video file path is invalid.');

        return '/_protected_media/'.ltrim(Str::after($normalizedPath, $normalizedRoot), '/');
    }
}
