<?php

use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('refreshes thumbnails of downloaded videos in the best available quality', function (): void {
    makeChannelWithVideo('hd');
    Http::fake(['*' => Http::response('hd-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $videosResource = Mockery::mock(VideosResource::class);
    $videosResource->shouldReceive('listVideos')
        ->once()
        ->with('snippet', Mockery::on(fn (array $params) => $params['id'] === 'hd-downloaded'))
        ->andReturn(new VideoListResponse(['items' => [[
            'id' => 'hd-downloaded',
            'snippet' => ['thumbnails' => [
                'medium' => ['url' => 'https://i.ytimg.com/vi/hd-downloaded/mqdefault.jpg'],
                'maxres' => ['url' => 'https://i.ytimg.com/vi/hd-downloaded/maxresdefault.jpg'],
            ]],
        ]]]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->videos = $videosResource;
    app()->instance(YouTube::class, $youtube);

    $this->artisan('youtube:sync-thumbnails', ['--refresh' => true])
        ->expectsOutput('Videos processed: 1; not found on YouTube: 0')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://i.ytimg.com/vi/hd-downloaded/maxresdefault.jpg');
    Http::assertSentCount(1);

    expect(Storage::disk('public')->get('thumbnails/videos/hd-downloaded.jpg'))->toBe('hd-bytes')
        ->and(Video::query()->firstOrFail()->getRawOriginal('thumbnail'))->toBe('thumbnails/videos/hd-downloaded.jpg');
});

it('keeps the thumbnail of a video missing on YouTube', function (): void {
    makeChannelWithVideo('gone');
    Http::fake(['*' => Http::response('hd-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $videosResource = Mockery::mock(VideosResource::class);
    $videosResource->shouldReceive('listVideos')->andReturn(new VideoListResponse(['items' => []]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->videos = $videosResource;
    app()->instance(YouTube::class, $youtube);

    $this->artisan('youtube:sync-thumbnails', ['--refresh' => true])
        ->expectsOutput('Videos processed: 1; not found on YouTube: 1')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Storage::disk('public')->get('thumbnails/videos/gone-downloaded.jpg'))->toBe('video-thumb');
});

it('falls back to a smaller size when the largest one is missing on the CDN', function (): void {
    makeChannelWithVideo('nomax');

    Http::fake([
        'i.ytimg.com/vi/nomax-downloaded/maxresdefault.jpg' => Http::response('', 404),
        'i.ytimg.com/vi/nomax-downloaded/sddefault.jpg' => Http::response('sd-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $videosResource = Mockery::mock(VideosResource::class);
    $videosResource->shouldReceive('listVideos')->andReturn(new VideoListResponse(['items' => [[
        'id' => 'nomax-downloaded',
        'snippet' => ['thumbnails' => [
            'maxres' => ['url' => 'https://i.ytimg.com/vi/nomax-downloaded/maxresdefault.jpg'],
            'standard' => ['url' => 'https://i.ytimg.com/vi/nomax-downloaded/sddefault.jpg'],
        ]],
    ]]]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->videos = $videosResource;
    app()->instance(YouTube::class, $youtube);

    $this->artisan('youtube:sync-thumbnails', ['--refresh' => true])->assertSuccessful();

    Http::assertSentCount(2);
    expect(Storage::disk('public')->get('thumbnails/videos/nomax-downloaded.jpg'))->toBe('sd-bytes');
});
