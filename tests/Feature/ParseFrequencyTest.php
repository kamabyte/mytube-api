<?php

use App\Console\Commands\ParseYoutubeVideos;
use App\Models\Channel;
use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
});

/**
 * Плейлист из указанных видео, у каждого — обложка maxres.
 *
 * @param  list<string>  $videoIds
 */
function playlistYoutube(array $videoIds): YouTube
{
    $items = Mockery::mock(PlaylistItemsResource::class);
    $items->shouldReceive('listPlaylistItems')->andReturn(new PlaylistItemListResponse(['items' => array_map(fn (string $id) => ['snippet' => [
        'publishedAt' => now()->subHour()->toIso8601String(),
        'resourceId' => ['videoId' => $id],
    ]], $videoIds)]));

    $videos = Mockery::mock(VideosResource::class);
    $videos->shouldReceive('listVideos')->andReturn(new VideoListResponse(['items' => array_map(fn (string $id) => [
        'id' => $id,
        'snippet' => [
            'title' => "Video {$id}",
            'description' => '',
            'publishedAt' => now()->subDay()->toIso8601String(),
            'thumbnails' => ['maxres' => ['url' => "https://i.ytimg.com/vi/{$id}/maxresdefault.jpg"]],
        ],
        'contentDetails' => ['duration' => 'PT10M'],
        'statistics' => ['viewCount' => '1'],
    ], $videoIds)]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->playlistItems = $items;
    $youtube->videos = $videos;
    app()->instance(YouTube::class, $youtube);

    return $youtube;
}

function frequencyPlaylist(array $attributes = []): Channel
{
    return Channel::query()->create(array_merge([
        'external_id' => 'PL-freq',
        'name' => 'Playlist',
        'uploads_playlist_id' => 'PL-freq',
        'is_playlist' => true,
    ], $attributes));
}

it('runs the parser every minute', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'youtube:parse-videos'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('downloads thumbnails of new videos only, not on every pass', function (): void {
    $playlist = frequencyPlaylist();
    $known = Video::withoutGlobalScopes()->create([
        'external_id' => 'known',
        'channel_id' => $playlist->id,
        'name' => 'Known',
        'thumbnail' => 'thumbnails/videos/known.jpg',
        'duration_seconds' => 600,
    ]);
    playlistYoutube(['known', 'fresh']);

    $this->artisan(ParseYoutubeVideos::class)->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://i.ytimg.com/vi/fresh/maxresdefault.jpg');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/vi/known/'));

    expect($known->fresh()->getRawOriginal('thumbnail'))->toBe('thumbnails/videos/known.jpg')
        ->and(Video::withoutGlobalScopes()->where('external_id', 'fresh')->sole()->getRawOriginal('thumbnail'))->toBe('thumbnails/videos/fresh.jpg');
});

it('checks a playlist at most every 10 minutes on schedule, but right away with --channel', function (): void {
    $playlist = frequencyPlaylist(['last_synced_at' => now()->subMinutes(3)]);
    $youtube = playlistYoutube([]);

    $this->artisan(ParseYoutubeVideos::class)->assertSuccessful();
    $youtube->playlistItems->shouldNotHaveReceived('listPlaylistItems');

    $this->artisan(ParseYoutubeVideos::class, ['--channel' => $playlist->id])->assertSuccessful();
    $youtube->playlistItems->shouldHaveReceived('listPlaylistItems')->once();
});

it('remembers checking an empty playlist, so it is not refetched every minute', function (): void {
    $playlist = frequencyPlaylist(['last_synced_at' => now()->subMinutes(11)]);
    playlistYoutube([]);

    $this->artisan(ParseYoutubeVideos::class)->assertSuccessful();

    expect($playlist->fresh()->last_synced_at->gt(now()->subMinute()))->toBeTrue();
});
