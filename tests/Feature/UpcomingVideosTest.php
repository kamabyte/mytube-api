<?php

use App\Models\Channel;
use App\Models\UpcomingVideo;
use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->travelTo('2026-10-05 12:00:00');
});

/**
 * @param  array<string, string>  $entries  videoId → published (ISO 8601), от новых к старым
 */
function upcomingFeed(array $entries = []): void
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom"><title>Feed</title>';

    foreach ($entries as $videoId => $published) {
        $xml .= "<entry><id>yt:video:{$videoId}</id><yt:videoId>{$videoId}</yt:videoId><title>{$videoId}</title><published>{$published}</published></entry>";
    }

    Http::fake([
        'www.youtube.com/feeds/*' => Http::response($xml.'</feed>', 200, ['Content-Type' => 'application/atom+xml']),
        '*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

/**
 * Видео в ответе videos.list: обычное (state = none) или ещё не вышедшее в эфир.
 *
 * @return array<string, mixed>
 */
function broadcastVideo(string $id, string $state = 'none', ?string $scheduledStart = null, ?string $actualStart = null): array
{
    return [
        'id' => $id,
        'snippet' => [
            'title' => "Video {$id}",
            'description' => '',
            'publishedAt' => '2026-10-05T10:00:00+00:00',
            'liveBroadcastContent' => $state,
            'thumbnails' => ['medium' => ['url' => "https://i.ytimg.com/vi/{$id}/mqdefault.jpg"]],
        ],
        'contentDetails' => ['duration' => $state === 'none' ? 'PT25M' : 'P0D'],
        'statistics' => ['viewCount' => '1'],
        'liveStreamingDetails' => array_filter([
            'scheduledStartTime' => $scheduledStart,
            'actualStartTime' => $actualStart,
        ]),
    ];
}

/**
 * @param  list<array<string, mixed>>  $videos  что отдаёт videos.list
 */
function upcomingYoutube(array $videos = []): YouTube
{
    $videosResource = Mockery::mock(VideosResource::class);
    $videosResource->shouldReceive('listVideos')->andReturn(new VideoListResponse(['items' => $videos]));

    $items = Mockery::mock(PlaylistItemsResource::class);
    $items->shouldReceive('listPlaylistItems')->andReturn(new PlaylistItemListResponse(['items' => []]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->videos = $videosResource;
    $youtube->playlistItems = $items;
    app()->instance(YouTube::class, $youtube);

    return $youtube;
}

function upcomingChannel(): Channel
{
    return Channel::query()->create([
        'external_id' => 'UCpremiere',
        'name' => 'Premiere channel',
        'uploads_playlist_id' => 'UUpremiere',
        'last_synced_at' => '2026-10-01 00:00:00',
    ]);
}

it('remembers a scheduled premiere instead of skipping it as short', function (): void {
    $channel = upcomingChannel();
    upcomingFeed(['premiere' => '2026-10-05T10:00:00+00:00']);
    upcomingYoutube([broadcastVideo('premiere', 'upcoming', scheduledStart: '2026-10-07T18:00:00+00:00')]);

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    expect(Video::withoutGlobalScopes()->count())->toBe(0)
        ->and($channel->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-05 10:00:00')
        ->and($channel->upcomingVideos()->sole()->only('external_id', 'check_after'))->toEqual([
            'external_id' => 'premiere',
            'check_after' => now()->parse('2026-10-07 18:30:00'),
        ]);
});

it('spends no quota on a premiere that is not due yet', function (): void {
    $channel = upcomingChannel();
    $channel->upcomingVideos()->create(['external_id' => 'premiere', 'check_after' => '2026-10-07 18:30:00']);
    upcomingFeed();
    $youtube = upcomingYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->videos->shouldNotHaveReceived('listVideos');
    expect(UpcomingVideo::query()->count())->toBe(1);
});

it('saves the premiere once it has aired, although the cursor is past it', function (): void {
    $channel = upcomingChannel();
    $channel->update(['last_synced_at' => '2026-10-05 11:00:00']);
    $channel->upcomingVideos()->create(['external_id' => 'premiere', 'check_after' => '2026-10-05 11:30:00']);
    upcomingFeed(['premiere' => '2026-10-05T10:00:00+00:00']);
    $youtube = upcomingYoutube([broadcastVideo('premiere')]);

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->videos->shouldHaveReceived('listVideos')->once()
        ->withArgs(fn (string $part, array $params) => $params['id'] === 'premiere');

    expect(Video::withoutGlobalScopes()->sole()->only('external_id', 'duration_seconds', 'channel_id'))->toBe([
        'external_id' => 'premiere',
        'duration_seconds' => 25 * 60,
        'channel_id' => $channel->id,
    ])
        ->and(UpcomingVideo::query()->count())->toBe(0)
        ->and($channel->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-05 11:00:00');
});

it('rechecks a broadcast that is still live later', function (): void {
    $channel = upcomingChannel();
    $channel->upcomingVideos()->create(['external_id' => 'stream', 'check_after' => '2026-10-05 11:00:00']);
    upcomingFeed();
    upcomingYoutube([broadcastVideo('stream', 'live', actualStart: '2026-10-05T10:30:00+00:00')]);

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    expect(Video::withoutGlobalScopes()->count())->toBe(0)
        ->and($channel->upcomingVideos()->sole()->check_after->toDateTimeString())->toBe('2026-10-05 12:30:00');
});

it('stops waiting for a broadcast that never ends', function (): void {
    $channel = upcomingChannel();
    $channel->upcomingVideos()->create(['external_id' => 'stream', 'check_after' => '2026-10-05 11:00:00']);
    upcomingFeed();
    upcomingYoutube([broadcastVideo('stream', 'live', actualStart: '2026-09-27T10:00:00+00:00')]);

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    expect(UpcomingVideo::query()->count())->toBe(0)
        ->and(Video::withoutGlobalScopes()->count())->toBe(0);
});

it('forgets a premiere that disappeared from YouTube', function (): void {
    $channel = upcomingChannel();
    $channel->upcomingVideos()->create(['external_id' => 'premiere', 'check_after' => '2026-10-05 11:00:00']);
    upcomingFeed();
    upcomingYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    expect(UpcomingVideo::query()->count())->toBe(0);
});
