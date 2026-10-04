<?php

use App\Models\Channel;
use App\Models\Video;
use App\Support\DeletePin;
use Google\Service\YouTube;
use Google\Service\YouTube\ChannelListResponse;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\PlaylistListResponse;
use Google\Service\YouTube\Resource\Channels as ChannelsResource;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Google\Service\YouTube\Resource\Playlists as PlaylistsResource;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
    $this->mediaRoot = useMediaDisk();
    app(DeletePin::class)->set('2468');
});

/**
 * @param  array<string, mixed>  $resources  channels / playlists / playlistItems / videos → items
 */
function fakeYoutube(array $resources): YouTube
{
    $youtube = Mockery::mock(YouTube::class);

    $map = [
        'channels' => [ChannelsResource::class, 'listChannels', ChannelListResponse::class],
        'playlists' => [PlaylistsResource::class, 'listPlaylists', PlaylistListResponse::class],
        'playlistItems' => [PlaylistItemsResource::class, 'listPlaylistItems', PlaylistItemListResponse::class],
        'videos' => [VideosResource::class, 'listVideos', VideoListResponse::class],
    ];

    foreach ($map as $property => [$resourceClass, $method, $responseClass]) {
        $resource = Mockery::mock($resourceClass);

        if (array_key_exists($property, $resources)) {
            $resource->shouldReceive($method)->andReturn(new $responseClass(['items' => $resources[$property]]));
        } else {
            $resource->shouldNotReceive($method);
        }

        $youtube->{$property} = $resource;
    }

    app()->instance(YouTube::class, $youtube);

    return $youtube;
}

function storedVideo(Channel $channel, string $slug, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => $slug,
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 300,
        'view_count' => 1,
        'file_size' => 11,
        'name' => "Video {$slug}",
        'thumbnail' => "thumbnails/videos/{$slug}.jpg",
        'published_at' => now()->subDay(),
        'downloaded_at' => now(),
    ], $attributes));
}

it('adds a channel from the web and opens it', function (): void {
    fakeYoutube(['channels' => [[
        'id' => 'UC-web',
        'snippet' => [
            'title' => 'Web Channel',
            'customUrl' => '@webchannel',
            'thumbnails' => ['high' => ['url' => 'https://yt3.ggpht.com/avatar.jpg']],
        ],
        'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-web']],
    ]], 'playlistItems' => []]);

    $response = $this->post('/channels', ['url' => 'https://www.youtube.com/@webchannel/videos', 'parse_popular' => true]);

    $channel = Channel::query()->where('external_id', 'UC-web')->sole();

    $response->assertRedirect("/channels/{$channel->id}");
    expect($channel->name)->toBe('Web Channel')
        ->and($channel->username)->toBe('webchannel')
        ->and($channel->uploads_playlist_id)->toBe('UU-web')
        ->and($channel->parse_latest)->toBeTrue()
        ->and($channel->parse_popular)->toBeTrue()
        ->and($channel->is_playlist)->toBeFalse();

    Storage::disk('public')->assertExists('thumbnails/channels/UC-web.jpg');

    // Сразу после ответа — прогон парсера по новому каналу.
    app(YouTube::class)->playlistItems->shouldHaveReceived('listPlaylistItems')
        ->withArgs(fn (string $part, array $params) => $params['playlistId'] === 'UU-web');
});

it('adds a playlist when the url points to one', function (): void {
    fakeYoutube([
        'playlists' => [['id' => 'PL-web', 'snippet' => ['title' => 'Избранное']]],
        'playlistItems' => [],
    ]);

    $this->post('/channels', ['url' => 'https://www.youtube.com/playlist?list=PL-web'])
        ->assertRedirect();

    expect(Channel::query()->where('external_id', 'PL-web')->sole())
        ->name->toBe('Избранное')
        ->is_playlist->toBeTrue();
});

it('explains in russian why a channel could not be added', function (): void {
    fakeYoutube(['channels' => []]);

    $this->from('/channels')
        ->post('/channels', ['url' => 'https://www.youtube.com/@nobody'])
        ->assertRedirect('/channels')
        ->assertSessionHasErrors(['url' => 'Канал не найден на YouTube.']);

    $this->post('/channels', ['url' => ''])->assertSessionHasErrors('url');

    expect(Channel::query()->count())->toBe(0);
});

it('removes a channel with its videos, thumbnails and files', function (): void {
    [$channel, $downloaded] = makeChannelWithVideo('gone');
    [$kept] = makeChannelWithVideo('kept');
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4", 'video-bytes');

    $this->delete("/channels/{$channel->id}", ['pin' => '2468'])
        ->assertRedirect('/channels');

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeFalse()
        ->and(Video::withoutGlobalScopes()->where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(File::isDirectory($this->mediaRoot."/videos/{$channel->id}"))->toBeFalse()
        ->and(Channel::query()->whereKey($kept->id)->exists())->toBeTrue();

    Storage::disk('public')->assertMissing($channel->getRawOriginal('thumbnail'));
});

it('refuses to remove a channel when the media disk is not mounted', function (): void {
    [$channel] = makeChannelWithVideo('offline');
    config()->set('filesystems.disks.media.root', '/nonexistent/media');

    $this->from("/channels/{$channel->id}")
        ->delete("/channels/{$channel->id}", ['pin' => '2468'])
        ->assertRedirect("/channels/{$channel->id}");

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

it('refuses to remove a video when the media disk is not mounted', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-off', 'name' => 'Offline', 'uploads_playlist_id' => 'UU-off']);
    $video = storedVideo($channel, 'off-1');
    config()->set('filesystems.disks.media.root', '/nonexistent/media');

    $this->from("/watch/{$video->id}")
        ->delete("/videos/{$video->id}", ['pin' => '2468'])
        ->assertRedirect("/watch/{$video->id}")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Медиадиск недоступен — видео не удалено');

    expect(Video::query()->whereKey($video->id)->exists())->toBeTrue();
});

it('removes a video for good: the file goes, the row stays out of every queue', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-rm', 'name' => 'Removal', 'uploads_playlist_id' => 'UU-rm']);
    $video = storedVideo($channel, 'rm-1');
    $sibling = storedVideo($channel, 'rm-2');
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4", 'video-bytes');
    File::put($this->mediaRoot."/videos/{$channel->id}/{$sibling->id}.mp4", 'video-bytes');
    // Файл другого видео с похожим id не должен задеть.
    File::put($this->mediaRoot."/videos/{$channel->id}/{$video->id}0.mp4", 'video-bytes');
    Storage::disk('public')->put('thumbnails/videos/rm-1.jpg', 'thumb');

    // Со страницы самого видео — на канал: этой страницы больше нет.
    $this->from("/watch/{$video->id}")
        ->delete("/videos/{$video->id}", ['pin' => '2468'])
        ->assertRedirect("/channels/{$channel->id}");

    $video = Video::withoutGlobalScopes()->findOrFail($video->id);

    expect(File::exists($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4"))->toBeFalse()
        ->and(File::exists($this->mediaRoot."/videos/{$channel->id}/{$sibling->id}.mp4"))->toBeTrue()
        ->and(File::exists($this->mediaRoot."/videos/{$channel->id}/{$video->id}0.mp4"))->toBeTrue()
        ->and($video->removed_at)->not->toBeNull()
        // Очередь воркера — is_downloaded = 0 AND is_unavailable = 0.
        ->and($video->is_downloaded)->toBeFalse()
        ->and($video->is_unavailable)->toBeTrue()
        ->and($video->file_size)->toBeNull();

    Storage::disk('public')->assertMissing('thumbnails/videos/rm-1.jpg');

    $this->get("/watch/{$video->id}")->assertNotFound();
    $this->getJson('/api/videos')->assertJsonCount(1, 'data');
    $this->getJson('/api/statistics')->assertJsonPath('total_videos', 1)->assertJsonPath('videos_in_progress', 0);

    // Парсер снова видит это видео на YouTube — и не трогает его.
    fakeYoutube([
        'playlistItems' => [[
            'snippet' => ['publishedAt' => now()->toIso8601String(), 'resourceId' => ['videoId' => 'rm-1']],
        ]],
    ]);

    $this->artisan('youtube:parse-videos', ['--channel' => $channel->id])->assertSuccessful();

    expect(Video::withoutGlobalScopes()->findOrFail($video->id))
        ->removed_at->not->toBeNull()
        ->is_unavailable->toBeTrue()
        ->thumbnail->toBeNull();
    Storage::disk('public')->assertMissing('thumbnails/videos/rm-1.jpg');
});

it('shows how many videos of a channel are still queued', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-q', 'name' => 'Queue']);
    storedVideo($channel, 'q-done');
    storedVideo($channel, 'q-wait', ['is_downloaded' => false, 'file_size' => null]);
    storedVideo($channel, 'q-gone', ['is_downloaded' => false, 'is_unavailable' => true]);

    $this->get("/channels/{$channel->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.videos_count', 1)
            ->where('channel.queued_count', 1));

    $this->get('/channels')
        ->assertInertia(fn (Assert $page) => $page->where('channels.0.queued_count', 1));
});

it('passes the console options of a channel through the web form', function (): void {
    fakeYoutube(['channels' => [[
        'id' => 'UC-full',
        'snippet' => ['title' => 'Full Channel', 'customUrl' => '@full'],
        'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-full']],
    ]], 'playlistItems' => []]);

    $this->post('/channels', [
        'url' => 'https://www.youtube.com/@full',
        'parse_latest' => true,
        'parse_popular' => false,
        'sync_from' => '2005-01-01',
        'playlist_id' => 'PL-instead',
    ])->assertRedirect();

    expect(Channel::query()->where('external_id', 'UC-full')->sole())
        ->uploads_playlist_id->toBe('PL-instead')
        ->parse_latest->toBeTrue()
        ->parse_popular->toBeFalse();

    // С датой парсер идёт по загрузкам дальше 50 последних — значит, last_synced_at
    // дошёл до него (после прогона без видео он остаётся прежним).
    expect(Channel::query()->where('external_id', 'UC-full')->sole()->last_synced_at->toDateString())
        ->toBe('2005-01-01');
});

it('passes the console options of a playlist through the web form', function (): void {
    fakeYoutube([
        'playlists' => [['id' => 'PL-named', 'snippet' => ['title' => 'Как на YouTube']]],
        'channels' => [],
        'playlistItems' => [],
    ]);

    $this->post('/channels', [
        'url' => 'https://www.youtube.com/playlist?list=PL-named',
        'parse_latest' => false,
        'parse_popular' => true,
        'name' => 'Своё название',
        'thumbnail' => 'https://i.ytimg.com/custom.jpg',
        'source_channel' => 'https://www.youtube.com/@missing',
    ])->assertRedirect()->assertInertiaFlash('toast.description', 'Канал-источник не найден — взята обложка плейлиста. Список видео обновится через минуту, скачивание — в порядке очереди.');

    expect(Channel::query()->where('external_id', 'PL-named')->sole())
        ->name->toBe('Своё название')
        ->parse_latest->toBeFalse()
        ->parse_popular->toBeTrue()
        ->is_playlist->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://i.ytimg.com/custom.jpg');
});

it('rejects a channel with both parsers turned off or a date in the future', function (): void {
    fakeYoutube([]);

    $this->post('/channels', ['url' => 'https://www.youtube.com/@off', 'parse_latest' => false, 'parse_popular' => false])
        ->assertSessionHasErrors('parse_latest');

    $this->post('/channels', ['url' => 'https://www.youtube.com/@future', 'sync_from' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors(['sync_from' => 'Дата не может быть в будущем.']);

    expect(Channel::query()->count())->toBe(0);
});

it('deletes a video from a card and stays on the page, catalog videos included', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-card', 'name' => 'Card', 'download_on_demand' => true]);
    $downloaded = storedVideo($channel, 'card-1');
    $catalog = storedVideo($channel, 'card-2', ['is_downloaded' => false, 'downloaded_at' => null, 'file_size' => null]);

    $this->from("/channels/{$channel->id}")
        ->delete("/videos/{$downloaded->id}", ['pin' => '2468'])
        ->assertRedirect("/channels/{$channel->id}");

    $this->from('/search?q=card')
        ->delete("/videos/{$catalog->id}", ['pin' => '2468'])
        ->assertRedirect('/search?q=card');

    expect(Video::withoutGlobalScopes()->whereKey([$downloaded->id, $catalog->id])->whereNotNull('removed_at')->count())->toBe(2);
    $this->get("/watch/{$catalog->id}")->assertNotFound();
});

it('removes only the file: the video stays in the catalog and can be downloaded again', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-unload', 'name' => 'Unload', 'download_on_demand' => true]);
    $video = storedVideo($channel, 'unload-1', ['download_requested_at' => now()->subDay()]);
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4", 'video-bytes');
    File::put($this->mediaRoot."/videos/{$channel->id}/{$video->id}.ru.vtt", 'subs');
    Storage::disk('public')->put('thumbnails/videos/unload-1.jpg', 'thumb');

    // Удаление файла — под тем же PIN, что и удаление видео.
    $this->delete("/videos/{$video->id}/file")->assertSessionHasErrors('pin');

    $this->from("/channels/{$channel->id}")
        ->delete("/videos/{$video->id}/file", ['pin' => '2468'])
        ->assertRedirect("/channels/{$channel->id}")
        ->assertInertiaFlash('toast.type', 'success');

    $video = Video::withoutGlobalScopes()->findOrFail($video->id);

    expect(File::exists($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4"))->toBeFalse()
        ->and(File::exists($this->mediaRoot."/videos/{$channel->id}/{$video->id}.ru.vtt"))->toBeFalse()
        ->and($video->is_downloaded)->toBeFalse()
        ->and($video->is_unavailable)->toBeFalse()
        ->and($video->removed_at)->toBeNull()
        ->and($video->download_requested_at)->toBeNull()
        ->and($video->file_size)->toBeNull()
        // Обложка остаётся — видео по-прежнему в каталоге.
        ->and($video->getRawOriginal('thumbnail'))->toBe('thumbnails/videos/unload-1.jpg');
    Storage::disk('public')->assertExists('thumbnails/videos/unload-1.jpg');

    // Не в очереди (канал «по запросу»), но открывается и просится снова.
    expect(Video::withoutGlobalScopes()->awaitingDownload()->count())->toBe(0);
    $this->get("/watch/{$video->id}")->assertInertia(fn (Assert $page) => $page->where('video.download_state', 'available'));
});

it('refuses to remove the file of a video that its channel would download again', function (): void {
    $channel = Channel::query()->create(['external_id' => 'UC-auto', 'name' => 'Auto']);
    $video = storedVideo($channel, 'auto-1');
    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4", 'video-bytes');

    $this->delete("/videos/{$video->id}/file", ['pin' => '2468'])
        ->assertRedirect()
        ->assertSessionHasErrors('file');

    expect(File::exists($this->mediaRoot."/videos/{$channel->id}/{$video->id}.mp4"))->toBeTrue()
        ->and($video->fresh()->is_downloaded)->toBeTrue();
});
