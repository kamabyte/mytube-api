<?php

use App\Models\Channel;
use Google\Service\YouTube;
use Google\Service\YouTube\ChannelListResponse;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\PlaylistListResponse;
use Google\Service\YouTube\Resource\Channels as ChannelsResource;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Google\Service\YouTube\Resource\Playlists as PlaylistsResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
});

/**
 * @param  array<int, array<string, mixed>>  $playlists
 * @param  array<int, array<string, mixed>>  $playlistItems
 * @param  array<int, array<string, mixed>>  $channels
 */
function fakeYoutubePlaylist(array $playlists, array $playlistItems = [], array $channels = []): void
{
    $playlistsResource = Mockery::mock(PlaylistsResource::class);
    $playlistsResource->shouldReceive('listPlaylists')
        ->andReturn(new PlaylistListResponse(['items' => $playlists]));

    $itemsResource = Mockery::mock(PlaylistItemsResource::class);
    $itemsResource->shouldReceive('listPlaylistItems')
        ->andReturn(new PlaylistItemListResponse(['items' => $playlistItems]));

    $channelsResource = Mockery::mock(ChannelsResource::class);
    $channelsResource->shouldReceive('listChannels')
        ->andReturn(new ChannelListResponse(['items' => $channels]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->playlists = $playlistsResource;
    $youtube->playlistItems = $itemsResource;
    $youtube->channels = $channelsResource;

    app()->instance(YouTube::class, $youtube);
}

it('adds a playlist as a channel and takes the artwork from the video owner', function (): void {
    fakeYoutubePlaylist(
        playlists: [[
            'id' => 'PL-awards',
            'snippet' => [
                'title' => 'Автоподбор — вручения',
                'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/playlist.jpg']],
            ],
        ]],
        playlistItems: [['snippet' => ['videoOwnerChannelId' => 'UC-source']]],
        channels: [[
            'id' => 'UC-source',
            'snippet' => ['thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/avatar.jpg']]],
        ]],
    );

    $this->artisan('youtube:add-playlist', ['playlistUrl' => 'https://www.youtube.com/playlist?list=PL-awards'])
        ->assertSuccessful();

    $playlist = Channel::query()->where('external_id', 'PL-awards')->sole();

    expect($playlist->name)->toBe('Автоподбор — вручения')
        ->and($playlist->is_playlist)->toBeTrue()
        ->and($playlist->uploads_playlist_id)->toBe('PL-awards')
        ->and($playlist->username)->toBeNull()
        ->and($playlist->parse_latest)->toBeTrue()
        ->and($playlist->getRawOriginal('thumbnail'))->toBe('thumbnails/channels/PL-awards.jpg');

    Http::assertSent(fn ($request) => $request->url() === 'https://i.ytimg.com/avatar.jpg');
    Storage::disk('public')->assertExists('thumbnails/channels/PL-awards.jpg');
});

it('honours the name, artwork and source channel overrides', function (): void {
    fakeYoutubePlaylist(
        playlists: [['id' => 'PL-awards', 'snippet' => ['title' => 'Ignored title']]],
        channels: [[
            'id' => 'UC-forced',
            'snippet' => ['thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/forced.jpg']]],
        ]],
    );

    $this->artisan('youtube:add-playlist', [
        'playlistUrl' => 'PL-awards',
        '--name' => 'Вручения',
        '--thumbnail' => 'https://example.test/custom.png',
        '--source-channel' => 'https://www.youtube.com/channel/UC-forced',
        '--parse-latest' => '0',
        '--parse-popular' => true,
    ])->assertSuccessful();

    $playlist = Channel::query()->where('external_id', 'PL-awards')->sole();

    expect($playlist->name)->toBe('Вручения')
        ->and($playlist->parse_latest)->toBeFalse()
        ->and($playlist->parse_popular)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/custom.png');
});

it('falls back to the playlist artwork when the videos have no owner', function (): void {
    fakeYoutubePlaylist(
        playlists: [['id' => 'PL-mixed', 'snippet' => [
            'title' => 'Mixed',
            'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/playlist.jpg']],
        ]]],
    );

    $this->artisan('youtube:add-playlist', ['playlistUrl' => 'PL-mixed'])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://i.ytimg.com/playlist.jpg');
});

it('adds a playlist whose source channel is tracked on its own without a warning', function (): void {
    Channel::query()->create(['external_id' => 'UC-source', 'name' => 'Source channel']);

    fakeYoutubePlaylist(
        playlists: [['id' => 'PL-awards', 'snippet' => ['title' => 'Вручения']]],
        playlistItems: [['snippet' => ['videoOwnerChannelId' => 'UC-source']]],
        channels: [['id' => 'UC-source', 'snippet' => []]],
    );

    $this->artisan('youtube:add-playlist', ['playlistUrl' => 'PL-awards'])
        ->doesntExpectOutputToContain('is already tracked on its own')
        ->assertSuccessful();
});

it('fails when the playlist is not visible', function (): void {
    fakeYoutubePlaylist(playlists: []);

    $this->artisan('youtube:add-playlist', ['playlistUrl' => 'PL-private'])
        ->expectsOutputToContain('was not found')
        ->assertFailed();

    expect(Channel::query()->count())->toBe(0);
});

it('rejects a url without a playlist id', function (): void {
    $this->artisan('youtube:add-playlist', ['playlistUrl' => 'https://www.youtube.com/@handle'])
        ->expectsOutputToContain('Could not read a playlist id')
        ->assertFailed();
});
