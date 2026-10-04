<?php

use App\Models\Channel;
use App\Models\Video;
use App\Models\VideoDownloadRun;
use Google\Service\YouTube;
use Google\Service\YouTube\ChannelListResponse;
use Google\Service\YouTube\PlaylistItemListResponse;
use Google\Service\YouTube\Resource\Channels as ChannelsResource;
use Google\Service\YouTube\Resource\PlaylistItems as PlaylistItemsResource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
});

function onDemandChannel(string $slug, bool $onDemand = true, array $attributes = []): Channel
{
    return Channel::query()->create(array_merge([
        'external_id' => "UC-{$slug}",
        'username' => $slug,
        'name' => "Channel {$slug}",
        'download_on_demand' => $onDemand,
    ], $attributes));
}

function catalogVideo(Channel $channel, string $slug, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => $slug,
        'channel_id' => $channel->id,
        'is_downloaded' => false,
        'duration_seconds' => 600,
        'view_count' => 1,
        'name' => "Video {$slug}",
        'published_at' => now()->subDay(),
    ], $attributes));
}

it('lists the catalog of an on-demand channel without queueing it', function (): void {
    $channel = onDemandChannel('od');
    catalogVideo($channel, 'od-1');
    catalogVideo($channel, 'od-2', ['is_downloaded' => true, 'file_size' => 10, 'published_at' => now()->subWeek()]);

    $this->get("/channels/{$channel->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.download_on_demand', true)
            ->where('channel.videos_count', 1)
            ->where('channel.catalog_count', 2)
            ->where('channel.queued_count', 0)
            ->where('videos.data.0.download_state', 'available')
            ->where('videos.data.1.download_state', 'downloaded'));

    expect(Video::withoutGlobalScopes()->awaitingDownload()->count())->toBe(0);
});

it('queues a requested video and keeps its place on a repeated request', function (): void {
    $video = catalogVideo(onDemandChannel('req'), 'req-1');

    $this->post("/videos/{$video->id}/download")
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Видео в очереди на загрузку');

    $requestedAt = $video->fresh()->download_requested_at;
    expect($requestedAt)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->post("/videos/{$video->id}/download")->assertRedirect();

    expect($video->fresh()->download_requested_at->equalTo($requestedAt))->toBeTrue()
        ->and(Video::withoutGlobalScopes()->awaitingDownload()->pluck('id')->all())->toBe([$video->id]);

    $this->get("/watch/{$video->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('video.download_state', 'queued')
            ->where('video.auto_download', false));
});

it('cancels a request', function (): void {
    $video = catalogVideo(onDemandChannel('cancel'), 'cancel-1', ['download_requested_at' => now()]);

    $this->delete("/videos/{$video->id}/download")
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Загрузка отменена');

    expect($video->fresh()->download_requested_at)->toBeNull();
});

it('refuses to queue an unavailable video', function (): void {
    $video = catalogVideo(onDemandChannel('gone'), 'gone-1', ['is_unavailable' => true]);

    $this->post("/videos/{$video->id}/download")
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect($video->fresh()->download_requested_at)->toBeNull();
});

it('does not touch removed videos', function (): void {
    $video = catalogVideo(onDemandChannel('rm'), 'rm-1', ['is_unavailable' => true, 'removed_at' => now()]);

    $this->post("/videos/{$video->id}/download")->assertNotFound();
});

it('queues a video when any of its sources downloads everything', function (): void {
    $playlist = onDemandChannel('pl', attributes: ['external_id' => 'PL-od', 'is_playlist' => true]);
    $video = catalogVideo($playlist, 'shared');
    $auto = onDemandChannel('auto', onDemand: false);
    $auto->videos()->attach($video);

    $this->get("/watch/{$video->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('video.download_state', 'queued')
            ->where('video.auto_download', true));
});

it('shows a running download, but not one abandoned hours ago', function (): void {
    $channel = onDemandChannel('run');
    $running = catalogVideo($channel, 'run-1', ['download_requested_at' => now()]);
    $stale = catalogVideo($channel, 'run-2', ['download_requested_at' => now()]);

    VideoDownloadRun::query()->create(['video_id' => $running->id, 'started_at' => now()->subMinute(), 'status' => 'running']);
    VideoDownloadRun::query()->create(['video_id' => $stale->id, 'started_at' => now()->subDay(), 'status' => 'running']);

    $this->get("/watch/{$running->id}")->assertInertia(fn (Assert $page) => $page->where('video.download_state', 'downloading'));
    $this->get("/watch/{$stale->id}")->assertInertia(fn (Assert $page) => $page->where('video.download_state', 'queued'));
});

it('switches a channel between on-demand and downloading everything', function (): void {
    $channel = onDemandChannel('switch');
    catalogVideo($channel, 'switch-1');
    catalogVideo($channel, 'switch-2');

    $this->patch("/channels/{$channel->id}", ['download_on_demand' => false])
        ->assertRedirect()
        ->assertInertiaFlash('toast.description', 'В очереди 2 видео.');

    expect($channel->fresh()->download_on_demand)->toBeFalse()
        ->and(Video::withoutGlobalScopes()->awaitingDownload()->count())->toBe(2);

    $this->patch("/channels/{$channel->id}", ['download_on_demand' => true])->assertRedirect();

    expect(Video::withoutGlobalScopes()->awaitingDownload()->count())->toBe(0);

    $this->patch("/channels/{$channel->id}", [])->assertSessionHasErrors('download_on_demand');
});

it('adds an on-demand channel from the web', function (): void {
    $channels = Mockery::mock(ChannelsResource::class);
    $channels->shouldReceive('listChannels')->andReturn(new ChannelListResponse(['items' => [[
        'id' => 'UC-new',
        'snippet' => ['title' => 'New', 'thumbnails' => ['high' => ['url' => 'https://yt3.ggpht.com/a.jpg']]],
        'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-new']],
    ]]]));
    $playlistItems = Mockery::mock(PlaylistItemsResource::class);
    $playlistItems->shouldReceive('listPlaylistItems')->andReturn(new PlaylistItemListResponse(['items' => []]));

    $youtube = Mockery::mock(YouTube::class);
    $youtube->channels = $channels;
    $youtube->playlistItems = $playlistItems;
    app()->instance(YouTube::class, $youtube);

    $this->post('/channels', ['url' => 'https://www.youtube.com/@new', 'download_on_demand' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast.description', 'Список видео обновится через минуту. Скачиваться будет только то, что вы попросите.');

    expect(Channel::query()->where('external_id', 'UC-new')->sole()->download_on_demand)->toBeTrue();
});

it('finds catalog videos in search', function (): void {
    $channel = onDemandChannel('find');
    catalogVideo($channel, 'find-1', ['name' => 'Лекция по физике']);
    catalogVideo($channel, 'find-2', ['name' => 'Лекция удалённая', 'is_unavailable' => true, 'removed_at' => now()]);

    $this->get('/search?q='.urlencode('лекция'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('videos.data', 1)
            ->where('videos.data.0.download_state', 'available'));
});

it('leaves the catalog out of the library statistics', function (): void {
    $channel = onDemandChannel('stats');
    catalogVideo($channel, 'stats-catalog');
    catalogVideo($channel, 'stats-requested', ['download_requested_at' => now()]);
    catalogVideo($channel, 'stats-done', ['is_downloaded' => true, 'file_size' => 10]);

    $this->getJson('/api/statistics')
        ->assertJsonPath('total_videos', 2)
        ->assertJsonPath('videos_in_progress', 1)
        ->assertJsonPath('total_duration_seconds', 1200);
});

it('keeps catalog videos out of the TV API', function (): void {
    $video = catalogVideo(onDemandChannel('tv'), 'tv-1');

    $this->getJson("/api/videos/{$video->id}")->assertNotFound();
});

it('registers the catalog binding even when routes are cached, as in production', function (): void {
    // route:cache — как при старте образа: файлы маршрутов тогда не выполняются,
    // и Route::bind оттуда не срабатывал — каталожные видео отвечали 404.
    $this->artisan('route:cache')->assertSuccessful();

    try {
        $app = require base_path('bootstrap/app.php');
        $app->make(Kernel::class)->bootstrap();

        expect($app->routesAreCached())->toBeTrue()
            ->and($app['router']->getBindingCallback('catalogVideo'))->not->toBeNull();
    } finally {
        $this->artisan('route:clear');
    }
});

it('shows what can still be downloaded in the statistics', function (): void {
    $channel = onDemandChannel('avail');
    catalogVideo($channel, 'avail-1');
    catalogVideo($channel, 'avail-2');
    catalogVideo($channel, 'avail-requested', ['download_requested_at' => now()]);
    catalogVideo($channel, 'avail-done', ['is_downloaded' => true, 'file_size' => 10]);
    catalogVideo($channel, 'avail-gone', ['is_unavailable' => true]);

    $this->getJson('/api/statistics')
        // Очередь считается отдельно (videos_in_progress), недоступное — нигде.
        ->assertJsonPath('videos_available', 2)
        ->assertJsonPath('videos_in_progress', 1);

    $this->getJson('/api/statistics/channels')
        ->assertJsonPath('0.video_count', 1)
        ->assertJsonPath('0.not_downloaded_count', 3);
});
