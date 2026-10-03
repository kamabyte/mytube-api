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
    useMediaDisk();
});

function fakeYoutubeChannel(array $item): void
{
    $resource = Mockery::mock(ChannelsResource::class);
    $resource->shouldReceive('listChannels')
        ->once()
        ->andReturn(new ChannelListResponse(['items' => [$item]]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->channels = $resource;

    app()->instance(YouTube::class, $youtube);
}

it('shows the channels and leaves on exit', function (): void {
    makeChannelWithVideo('listed');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'list')
        ->expectsOutputToContain('Channel listed')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();
});

it('adds a channel with all of its fields', function (): void {
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    fakeYoutubeChannel([
        'id' => 'UC-new',
        'snippet' => [
            'title' => 'New channel',
            'customUrl' => '@newchannel',
            'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/new.jpg']],
        ],
        'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-new']],
    ]);

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'add')
        ->expectsQuestion('Channel url, @handle or channel id', 'https://www.youtube.com/@newchannel')
        ->expectsConfirmation('Parse latest uploads?', 'yes')
        ->expectsConfirmation('Parse popular videos?', 'yes')
        ->expectsQuestion('Uploads playlist id', '')
        ->expectsQuestion('Fetch videos published after', '2026-01-31')
        ->expectsOutputToContain('The channel was saved.')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    $channel = Channel::query()->where('external_id', 'UC-new')->sole();

    expect($channel->name)->toBe('New channel')
        ->and($channel->username)->toBe('newchannel')
        ->and($channel->uploads_playlist_id)->toBe('UU-new')
        ->and($channel->parse_latest)->toBeTrue()
        ->and($channel->parse_popular)->toBeTrue()
        ->and($channel->last_synced_at->toDateString())->toBe('2026-01-31')
        ->and($channel->getRawOriginal('thumbnail'))->toBe('thumbnails/channels/UC-new.jpg');

    Storage::disk('public')->assertExists('thumbnails/channels/UC-new.jpg');
});

it('keeps the custom playlist id and the disabled parsers when they are asked for', function (): void {
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    fakeYoutubeChannel([
        'id' => 'UC-custom',
        'snippet' => ['title' => 'Custom', 'customUrl' => '@custom'],
        'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-reported']],
    ]);

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'add')
        ->expectsQuestion('Channel url, @handle or channel id', '@custom')
        ->expectsConfirmation('Parse latest uploads?', 'no')
        ->expectsConfirmation('Parse popular videos?', 'no')
        ->expectsQuestion('Uploads playlist id', 'PL-custom')
        ->expectsQuestion('Fetch videos published after', '')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    $channel = Channel::query()->where('external_id', 'UC-custom')->sole();

    expect($channel->uploads_playlist_id)->toBe('PL-custom')
        ->and($channel->parse_latest)->toBeFalse()
        ->and($channel->parse_popular)->toBeFalse()
        ->and($channel->last_synced_at)->toBeNull();
});

it('adds a playlist from the menu', function (): void {
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $playlists = Mockery::mock(PlaylistsResource::class);
    $playlists->shouldReceive('listPlaylists')
        ->once()
        ->andReturn(new PlaylistListResponse(['items' => [[
            'id' => 'PL-awards',
            'snippet' => ['title' => 'Автоподбор — вручения'],
        ]]]));

    $playlistItems = Mockery::mock(PlaylistItemsResource::class);
    $playlistItems->shouldReceive('listPlaylistItems')
        ->once()
        ->andReturn(new PlaylistItemListResponse(['items' => [[
            'snippet' => ['videoOwnerChannelId' => 'UC-source'],
        ]]]));

    $channels = Mockery::mock(ChannelsResource::class);
    $channels->shouldReceive('listChannels')
        ->once()
        ->andReturn(new ChannelListResponse(['items' => [[
            'id' => 'UC-source',
            'snippet' => ['thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/avatar.jpg']]],
        ]]]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->playlists = $playlists;
    $youtube->playlistItems = $playlistItems;
    $youtube->channels = $channels;

    app()->instance(YouTube::class, $youtube);

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'add-playlist')
        ->expectsQuestion('Playlist url or id', 'https://www.youtube.com/playlist?list=PL-awards')
        ->expectsQuestion('Name', '')
        ->expectsQuestion('Source channel url, @handle or channel id', '')
        ->expectsQuestion('Artwork url', '')
        ->expectsConfirmation('Parse latest uploads?', 'yes')
        ->expectsConfirmation('Parse popular videos?', 'no')
        ->expectsOutputToContain('The playlist was saved.')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    $playlist = Channel::query()->where('external_id', 'PL-awards')->sole();

    expect($playlist->name)->toBe('Автоподбор — вручения')
        ->and($playlist->is_playlist)->toBeTrue()
        ->and($playlist->uploads_playlist_id)->toBe('PL-awards');
});

it('edits the fields of a channel', function (): void {
    [$channel] = makeChannelWithVideo('editable');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'edit')
        ->expectsQuestion('Which channel do you want to edit?', (string) $channel->id)
        ->expectsQuestion('What do you want to change?', 'name')
        ->expectsQuestion('Name', 'Renamed channel')
        ->expectsQuestion('What do you want to change?', 'username')
        ->expectsQuestion('Username', '@renamed')
        ->expectsQuestion('What do you want to change?', 'parse_popular')
        ->expectsConfirmation('Parse popular videos?', 'yes')
        ->expectsQuestion('What do you want to change?', 'last_synced_at')
        ->expectsQuestion('Last synced at', '2026-02-01 10:00:00')
        ->expectsQuestion('What do you want to change?', 'uploads_playlist_id')
        ->expectsQuestion('Uploads playlist id', '')
        ->expectsQuestion('What do you want to change?', 'back')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    $channel->refresh();

    expect($channel->name)->toBe('Renamed channel')
        ->and($channel->username)->toBe('renamed')
        ->and($channel->parse_popular)->toBeTrue()
        ->and($channel->last_synced_at->toDateTimeString())->toBe('2026-02-01 10:00:00')
        ->and($channel->uploads_playlist_id)->toBeNull();
});

it('rejects a username that another channel already uses', function (): void {
    [$channel] = makeChannelWithVideo('first');
    makeChannelWithVideo('second');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'edit')
        ->expectsQuestion('Which channel do you want to edit?', (string) $channel->id)
        ->expectsQuestion('What do you want to change?', 'username')
        ->expectsQuestion('Username', 'second')
        ->expectsOutputToContain('Another channel already uses this username.')
        ->assertFailed();

    expect($channel->refresh()->username)->toBe('first');
});

it('rejects a date that cannot be parsed', function (): void {
    [$channel] = makeChannelWithVideo('dates');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'edit')
        ->expectsQuestion('Which channel do you want to edit?', (string) $channel->id)
        ->expectsQuestion('What do you want to change?', 'last_synced_at')
        ->expectsQuestion('Last synced at', 'позавчера')
        ->expectsOutputToContain('Enter a date like 2026-01-31.')
        ->assertFailed();

    expect($channel->refresh()->last_synced_at)->toBeNull();
});

it('removes a channel through the remove command', function (): void {
    [$channel] = makeChannelWithVideo('doomed');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'remove')
        ->expectsQuestion('Which channel do you want to remove?', (string) $channel->id)
        ->expectsConfirmation(
            'Permanently remove "Channel doomed" with 2 video(s) and 0 B of downloaded files?',
            'yes',
        )
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->toBeNull();

    Storage::disk('public')->assertDirectoryEmpty('thumbnails/videos');
});

it('warns instead of prompting when there are no channels yet', function (): void {
    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'edit')
        ->expectsOutputToContain('There are no channels yet.')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();
});

it('goes back to the menu from the channel picker', function (): void {
    [$channel] = makeChannelWithVideo('kept');

    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'edit')
        ->expectsQuestion('Which channel do you want to edit?', 'back')
        ->expectsQuestion('MyTube channels', 'remove')
        ->expectsQuestion('Which channel do you want to remove?', 'back')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->not->toBeNull();
});

it('goes back to the menu when the channel url is left empty', function (): void {
    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'add')
        ->expectsQuestion('Channel url, @handle or channel id', '')
        ->expectsQuestion('MyTube channels', 'exit')
        ->assertSuccessful();

    expect(Channel::query()->count())->toBe(0);
});

it('rejects a date the add form cannot parse', function (): void {
    $this->artisan('youtube:channels')
        ->expectsQuestion('MyTube channels', 'add')
        ->expectsQuestion('Channel url, @handle or channel id', '@somebody')
        ->expectsConfirmation('Parse latest uploads?', 'yes')
        ->expectsConfirmation('Parse popular videos?', 'no')
        ->expectsQuestion('Uploads playlist id', '')
        ->expectsQuestion('Fetch videos published after', 'на прошлой неделе')
        ->expectsOutputToContain('Enter a date like 2026-01-31.')
        ->assertFailed();

    expect(Channel::query()->count())->toBe(0);
});

it('refuses to run non-interactively', function (): void {
    $this->artisan('youtube:channels', ['--no-interaction' => true])
        ->expectsOutputToContain('interactive')
        ->assertFailed();
});
