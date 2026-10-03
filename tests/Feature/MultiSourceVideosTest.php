<?php

use App\Models\Channel;
use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
    $this->mediaRoot = useMediaDisk();
});

/**
 * YouTube, в котором плейлист (любой) состоит из указанных видео.
 *
 * @param  list<string>  $videoIds
 */
function fakeYoutubeListing(array $videoIds): void
{
    $items = array_map(fn (string $id) => ['snippet' => [
        'publishedAt' => now()->subHour()->toIso8601String(),
        'resourceId' => ['videoId' => $id],
    ]], $videoIds);

    $videos = array_map(fn (string $id) => [
        'id' => $id,
        'snippet' => [
            'title' => "Video {$id}",
            'description' => '',
            'publishedAt' => now()->subDay()->toIso8601String(),
            'thumbnails' => ['medium' => ['url' => "https://i.ytimg.com/{$id}.jpg"]],
        ],
        'contentDetails' => ['duration' => 'PT10M'],
        'statistics' => ['viewCount' => '5'],
    ], $videoIds);

    $playlistItems = Mockery::mock(PlaylistItemsResource::class);
    $playlistItems->shouldReceive('listPlaylistItems')
        ->andReturn(new PlaylistItemListResponse(['items' => $items]));

    $videosResource = Mockery::mock(VideosResource::class);
    $videosResource->shouldReceive('listVideos')
        ->andReturn(new VideoListResponse(['items' => $videos]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->playlistItems = $playlistItems;
    $youtube->videos = $videosResource;

    app()->instance(YouTube::class, $youtube);
}

function trackedChannel(): Channel
{
    return Channel::query()->create([
        'external_id' => 'UC-author',
        'name' => 'Author',
        'uploads_playlist_id' => 'UU-author',
    ]);
}

function trackedPlaylist(string $id = 'PL-best'): Channel
{
    return Channel::query()->create([
        'external_id' => $id,
        'name' => "Playlist {$id}",
        'uploads_playlist_id' => $id,
        'is_playlist' => true,
    ]);
}

function parse(Channel $channel, array $videoIds): void
{
    fakeYoutubeListing($videoIds);

    test()->artisan('youtube:parse-videos', ['--channel' => $channel->id])->assertSuccessful();
}

function sources(string $externalId): array
{
    return Video::withoutGlobalScopes()->where('external_id', $externalId)->sole()
        ->channels()->orderBy('channels.id')->pluck('channels.id')->all();
}

it('keeps a video in both the channel and the playlist', function (): void {
    $channel = trackedChannel();
    $playlist = trackedPlaylist();

    parse($channel, ['v1', 'v2']);
    parse($playlist, ['v1']);

    $video = Video::withoutGlobalScopes()->where('external_id', 'v1')->sole();

    expect(Video::withoutGlobalScopes()->count())->toBe(2)
        ->and($video->channel_id)->toBe($channel->id)
        ->and(sources('v1'))->toBe([$channel->id, $playlist->id])
        ->and(sources('v2'))->toBe([$channel->id]);

    Video::withoutGlobalScopes()->update(['is_downloaded' => true]);

    $this->getJson("/api/videos?filter[channel_id]={$playlist->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $video->id);

    $this->getJson("/api/videos?filter[channel_id]={$channel->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/home')->assertOk()->assertJsonCount(2, 'channels');
    $this->get("/channels/{$playlist->id}")->assertOk();
});

it('hands the video over from the playlist to the author channel', function (): void {
    $playlist = trackedPlaylist();
    $channel = trackedChannel();

    parse($playlist, ['v1']);
    expect(Video::withoutGlobalScopes()->sole()->channel_id)->toBe($playlist->id);

    parse($channel, ['v1']);
    expect(Video::withoutGlobalScopes()->sole()->channel_id)->toBe($channel->id)
        ->and(sources('v1'))->toBe([$playlist->id, $channel->id]);

    // Второй плейлист основной источник уже не отбирает.
    parse(trackedPlaylist('PL-other'), ['v1']);
    expect(Video::withoutGlobalScopes()->sole()->channel_id)->toBe($channel->id);
});

it('detaches videos that were taken out of the playlist on youtube', function (): void {
    $playlist = trackedPlaylist();
    $channel = trackedChannel();

    parse($playlist, ['shared', 'lonely', 'kept']);
    parse($channel, ['shared']);

    $lonely = Video::withoutGlobalScopes()->where('external_id', 'lonely')->sole();
    $lonely->forceFill(['is_downloaded' => true])->save();
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$playlist->id}");
    File::put($this->mediaRoot."/videos/{$playlist->id}/{$lonely->id}.mp4", 'bytes');

    parse($playlist, ['kept']);

    expect(sources('shared'))->toBe([$channel->id])
        ->and(sources('kept'))->toBe([$playlist->id])
        ->and(Video::withoutGlobalScopes()->where('external_id', 'lonely')->exists())->toBeFalse()
        ->and(File::exists($this->mediaRoot."/videos/{$playlist->id}/{$lonely->id}.mp4"))->toBeFalse();
});

it('keeps the removal mark of a video taken out of the playlist', function (): void {
    $playlist = trackedPlaylist();

    parse($playlist, ['gone', 'kept']);
    Video::withoutGlobalScopes()->where('external_id', 'gone')->update(['removed_at' => now(), 'is_unavailable' => true]);

    parse($playlist, ['kept']);

    expect(Video::withoutGlobalScopes()->where('external_id', 'gone')->sole()->removed_at)->not->toBeNull()
        ->and(sources('gone'))->toBe([]);
});

it('does not detach anything when youtube returns an empty playlist', function (): void {
    $playlist = trackedPlaylist();

    parse($playlist, ['v1']);
    parse($playlist, []);

    expect(sources('v1'))->toBe([$playlist->id]);
});

it('keeps videos and files that another source still holds when a channel is removed', function (): void {
    $channel = trackedChannel();
    $playlist = trackedPlaylist();

    parse($channel, ['shared', 'own']);
    parse($playlist, ['shared']);
    Video::withoutGlobalScopes()->update(['is_downloaded' => true]);

    $shared = Video::withoutGlobalScopes()->where('external_id', 'shared')->sole();
    $own = Video::withoutGlobalScopes()->where('external_id', 'own')->sole();
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$shared->id}.mp4", 'bytes');
    File::put($this->mediaRoot."/videos/{$channel->id}/{$own->id}.mp4", 'bytes');

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
        ->assertSuccessful();

    expect($shared->fresh()->channel_id)->toBe($playlist->id)
        ->and(sources('shared'))->toBe([$playlist->id])
        ->and(Video::withoutGlobalScopes()->whereKey($own->id)->exists())->toBeFalse()
        ->and(File::exists($this->mediaRoot."/videos/{$channel->id}/{$own->id}.mp4"))->toBeFalse()
        ->and(File::exists($this->mediaRoot."/videos/{$channel->id}/{$shared->id}.mp4"))->toBeTrue();

    // Файл остался в каталоге прежнего источника — плеер находит его и там.
    $this->get("/api/videos/{$shared->id}/stream")
        ->assertOk()
        ->assertHeader('X-Accel-Redirect', "/_protected_media/videos/{$channel->id}/{$shared->id}.mp4");
});
