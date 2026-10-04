<?php

use App\Models\Channel;
use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
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

/**
 * @param  array<string, string>  $entries  videoId → published (ISO 8601), от новых к старым
 */
function feedXml(array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom"><title>Feed</title>';

    foreach ($entries as $videoId => $published) {
        $xml .= "<entry><id>yt:video:{$videoId}</id><yt:videoId>{$videoId}</yt:videoId><title>{$videoId}</title><published>{$published}</published></entry>";
    }

    return $xml.'</feed>';
}

function fakeFeed(string|int $body): void
{
    Http::fake([
        'www.youtube.com/feeds/*' => is_int($body) ? Http::response('', $body) : Http::response($body, 200, ['Content-Type' => 'application/atom+xml']),
        '*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

/**
 * YouTube API, которым парсер пользуется только по необходимости.
 *
 * @param  list<string>  $videoIds  что отдаёт videos.list
 */
function feedYoutube(array $videoIds = [], array $playlistItems = []): YouTube
{
    $videos = Mockery::mock(VideosResource::class);
    $videos->shouldReceive('listVideos')->andReturn(new VideoListResponse(['items' => array_map(fn (string $id) => [
        'id' => $id,
        'snippet' => [
            'title' => "Video {$id}",
            'description' => '',
            'publishedAt' => now()->toIso8601String(),
            'thumbnails' => ['medium' => ['url' => "https://i.ytimg.com/vi/{$id}/mqdefault.jpg"]],
        ],
        'contentDetails' => ['duration' => 'PT10M'],
        'statistics' => ['viewCount' => '1'],
    ], $videoIds)]));

    $items = Mockery::mock(PlaylistItemsResource::class);
    $items->shouldReceive('listPlaylistItems')->andReturn(new PlaylistItemListResponse(['items' => $playlistItems]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->videos = $videos;
    $youtube->playlistItems = $items;
    app()->instance(YouTube::class, $youtube);

    return $youtube;
}

function feedChannel(array $attributes = []): Channel
{
    return Channel::query()->create(array_merge([
        'external_id' => 'UCfeed',
        'name' => 'Feed channel',
        'uploads_playlist_id' => 'UUfeed',
        'last_synced_at' => '2026-10-01 00:00:00',
    ], $attributes));
}

it('spends no API quota on a channel without new uploads', function (): void {
    $channel = feedChannel();
    fakeFeed(feedXml(['old-1' => '2026-09-30T10:00:00+00:00', 'old-2' => '2026-09-20T10:00:00+00:00']));
    $youtube = feedYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->playlistItems->shouldNotHaveReceived('listPlaylistItems');
    $youtube->videos->shouldNotHaveReceived('listVideos');
    expect(Video::withoutGlobalScopes()->count())->toBe(0)
        ->and($channel->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-01 00:00:00');
});

it('takes new uploads from the feed and details from one videos.list call', function (): void {
    $channel = feedChannel();
    fakeFeed(feedXml([
        'new-2' => '2026-10-03T12:00:00+00:00',
        'new-1' => '2026-10-02T12:00:00+00:00',
        'old-1' => '2026-09-30T10:00:00+00:00',
    ]));
    $youtube = feedYoutube(['new-2', 'new-1']);

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->playlistItems->shouldNotHaveReceived('listPlaylistItems');
    $youtube->videos->shouldHaveReceived('listVideos')->once()
        ->withArgs(fn (string $part, array $params) => $params['id'] === 'new-2,new-1');

    expect(Video::withoutGlobalScopes()->pluck('external_id')->sort()->values()->all())->toBe(['new-1', 'new-2'])
        ->and($channel->fresh()->last_synced_at->toDateTimeString())->toBe('2026-10-03 12:00:00');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.youtube.com/feeds/videos.xml?channel_id=UCfeed');
});

it('falls back to the API when the feed is unavailable', function (): void {
    feedChannel();
    fakeFeed(500);
    $youtube = feedYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->playlistItems->shouldHaveReceived('listPlaylistItems')
        ->withArgs(fn (string $part, array $params) => $params['playlistId'] === 'UUfeed');
});

it('falls back to the API when every feed entry is new', function (): void {
    feedChannel();
    $entries = [];
    foreach (range(1, 15) as $i) {
        $entries["v{$i}"] = now()->subMinutes($i)->toIso8601String();
    }
    fakeFeed(feedXml($entries));
    $youtube = feedYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    $youtube->playlistItems->shouldHaveReceived('listPlaylistItems');
});

it('does not use the feed for a channel with a custom uploads playlist', function (): void {
    feedChannel(['uploads_playlist_id' => 'PLcustom']);
    fakeFeed(feedXml([]));
    $youtube = feedYoutube();

    $this->artisan('youtube:parse-videos')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/feeds/'));
    $youtube->playlistItems->shouldHaveReceived('listPlaylistItems')
        ->withArgs(fn (string $part, array $params) => $params['playlistId'] === 'PLcustom');
});
