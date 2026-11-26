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
        ->assertInertiaFlash('toast.message', 'Видео в очереди на загрузку')
        ->assertInertiaFlash('toast.id', "download-queued-{$video->id}");

    $requestedAt = $video->fresh()->download_requested_at;
    expect($requestedAt)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->post("/videos/{$video->id}/download")
        ->assertRedirect()
        ->assertInertiaFlash('toast.id', "download-queued-{$video->id}");

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

it('starts automatic downloads from the time a channel is switched to new videos only', function (): void {
    $cutoff = now('UTC')->startOfSecond();
    $this->travelTo($cutoff);
    $channel = onDemandChannel('from-now', attributes: ['parse_latest' => false]);
    $old = catalogVideo($channel, 'old');
    $requested = catalogVideo($channel, 'requested', ['download_requested_at' => $cutoff]);
    $saved = catalogVideo($channel, 'saved', ['is_downloaded' => true]);
    $channel->update(['last_synced_at' => $cutoff->copy()->subDay()]);

    $settings = ['download_on_demand' => false, 'download_new_only' => true];
    $this->from("/channels/{$channel->id}")->patch("/channels/{$channel->id}", $settings)
        ->assertRedirect("/channels/{$channel->id}")
        ->assertInertiaFlash('toast.message', "«{$channel->name}» — скачиваются только новые");

    $atCutoff = catalogVideo($channel, 'at-cutoff', ['published_at' => $cutoff]);
    $this->travel(5)->minutes();
    $lateDiscovery = catalogVideo($channel, 'late-discovery', ['published_at' => $cutoff->copy()->subSecond()]);
    $recent = catalogVideo($channel, 'recent', ['published_at' => now()]);
    $unknownDate = catalogVideo($channel, 'unknown-date', ['published_at' => null]);

    // Повтор запроса оставляет исходную дату. Курсор парсера живёт отдельно.
    $this->patchJson("/api/channels/{$channel->id}", $settings)
        ->assertOk()
        ->assertJsonPath('data.auto_download_from', $cutoff->toJSON())
        ->assertJsonPath('data.download_on_demand', false);

    expect($channel->fresh()->parse_latest)->toBeTrue()
        ->and($channel->fresh()->last_synced_at->equalTo($cutoff->copy()->subDay()))->toBeTrue()
        ->and($saved->fresh()->is_downloaded)->toBeTrue();

    $this->getJson('/downloads')
        ->assertJsonPath('queue.*.id', [$requested->id, $atCutoff->id, $recent->id])
        ->assertJsonPath('queued_count', 3);
    $this->getJson('/api/statistics')->assertJsonPath('videos_available', 3);
    $this->get("/channels/{$channel->id}")->assertInertia(fn (Assert $page) => $page
        ->where('channel.queued_count', 3)
        ->where('channel.auto_download_from', $cutoff->toJSON()));

    foreach ([$old, $lateDiscovery, $unknownDate] as $video) {
        $this->getJson("/api/videos/{$video->id}")
            ->assertJsonPath('data.download_state', 'available')
            ->assertJsonPath('data.auto_download', false);
    }

    // Другой источник по-прежнему может скачать старое видео.
    $auto = onDemandChannel('all', onDemand: false);
    $auto->videos()->attach($old);
    $this->getJson("/api/videos/{$old->id}")->assertJsonPath('data.download_state', 'queued');

    $this->patchJson("/api/channels/{$channel->id}", ['download_on_demand' => false])
        ->assertOk()
        ->assertJsonPath('data.auto_download_from', null);
    expect(Video::catalog()->awaitingDownload()->count())->toBe(6);
});

it('overrides the mode of one video and keeps an in-progress download visible', function (): void {
    $channel = onDemandChannel('override');
    $video = catalogVideo($channel, 'override-1');
    $url = "/api/videos/{$video->id}";

    $this->patchJson($url, [])->assertJsonValidationErrors('download_on_demand');
    $this->patchJson($url, ['download_on_demand' => false])
        ->assertOk()
        ->assertJsonPath('data.download_on_demand', false)
        ->assertJsonPath('data.download_state', 'queued');

    $run = VideoDownloadRun::query()->create(['video_id' => $video->id, 'started_at' => now(), 'status' => 'running']);
    $this->patchJson($url, ['download_on_demand' => true])
        ->assertOk()
        ->assertJsonPath('data.download_state', 'downloading')
        ->assertJsonPath('data.auto_download', false);
    $this->getJson('/downloads')
        ->assertJsonPath('current.id', $video->id)
        ->assertJsonPath('queued_count', 0);
    $run->update(['status' => 'ok', 'finished_at' => now()]);

    $this->postJson("{$url}/download")
        ->assertOk()
        ->assertJsonPath('data.download_state', 'queued');
    $this->deleteJson("{$url}/download")->assertJsonPath('data.download_state', 'available');

    $this->patchJson($url, ['download_on_demand' => null])
        ->assertOk()
        ->assertJsonPath('data.download_on_demand', null)
        ->assertJsonPath('data.download_state', 'available');

    $this->from("/watch/{$video->id}")->patch("/videos/{$video->id}", ['download_on_demand' => false])
        ->assertRedirect("/watch/{$video->id}");
    $this->getJson($url)->assertJsonPath('data.download_state', 'queued');

    $this->patchJson("/api/channels/{$channel->id}", ['download_on_demand' => true, 'sync_paused' => true])->assertOk();
    $this->getJson($url)->assertJsonPath('data.download_state', 'available');
    $this->postJson("{$url}/download")->assertJsonPath('data.download_state', 'queued');
});

it('pauses channel imports and automatic downloads while keeping the library and manual requests', function (): void {
    $channel = onDemandChannel('paused', onDemand: false, attributes: ['parse_popular' => true]);
    $downloaded = catalogVideo($channel, 'paused-downloaded', ['is_downloaded' => true]);
    $automatic = catalogVideo($channel, 'paused-automatic');
    $manual = catalogVideo($channel, 'paused-manual', ['download_requested_at' => now()]);
    $channel->upcomingVideos()->create(['external_id' => 'paused-premiere', 'check_after' => now()->subMinute()]);
    $this->instance(YouTube::class, Mockery::mock(YouTube::class));

    $this->patch("/channels/{$channel->id}", ['download_on_demand' => false, 'sync_paused' => true])
        ->assertRedirect();

    $this->artisan('youtube:parse-videos', ['--channel' => $channel->id, '--verbose' => true])
        ->expectsOutputToContain('Обновление канала приостановлено')
        ->assertSuccessful();
    $this->artisan('youtube:parse-videos', ['--channel' => $channel->id, '--popular' => true, '--verbose' => true])
        ->expectsOutputToContain('Обновление канала приостановлено')
        ->assertSuccessful();
    Http::assertNothingSent();

    expect($channel->fresh()->sync_paused)->toBeTrue()
        ->and($channel->fresh()->last_synced_at)->toBeNull()
        ->and($channel->upcomingVideos()->count())->toBe(1)
        ->and(Video::catalog()->count())->toBe(3)
        ->and($downloaded->fresh()->is_downloaded)->toBeTrue()
        ->and(Video::catalog()->awaitingDownload()->pluck('videos.id')->all())->toBe([$manual->id]);

    $this->get("/channels/{$channel->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('youtubeUrl', 'https://www.youtube.com/channel/UC-paused')
            ->where('channel.sync_paused', true));

    $this->patch("/channels/{$channel->id}", ['download_on_demand' => true, 'sync_paused' => false])
        ->assertRedirect();

    expect($channel->fresh()->sync_paused)->toBeFalse()
        ->and($channel->fresh()->parse_latest)->toBeTrue()
        ->and($channel->fresh()->parse_popular)->toBeTrue()
        ->and(Video::catalog()->awaitingDownload()->whereKey($automatic->id)->exists())->toBeFalse();
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

it('keeps catalog videos out of TV lists unless asked for, and lets the TV request them', function (): void {
    $channel = onDemandChannel('tv');
    $video = catalogVideo($channel, 'tv-1');
    catalogVideo($channel, 'tv-done', ['is_downloaded' => true, 'file_size' => 1, 'published_at' => now()->subWeek()]);

    // Старый контракт (Android, прежние tvOS): только скачанное, без лишних фильтров.
    $this->getJson('/api/videos')->assertJsonCount(1, 'data')->assertJsonPath('data.0.download_state', 'downloaded');

    $this->getJson('/api/videos?filter[catalog]=1&sort=new')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $video->id)
        ->assertJsonPath('data.0.download_state', 'available')
        ->assertJsonPath('data.0.video_url', null);

    $this->getJson('/api/channels')
        ->assertJsonPath('data.0.videos_count', 1)
        ->assertJsonPath('data.0.catalog_count', 2)
        ->assertJsonPath('data.0.download_on_demand', true);

    $this->postJson("/api/videos/{$video->id}/download")
        ->assertOk()
        ->assertJsonPath('data.download_state', 'queued');

    $this->deleteJson("/api/videos/{$video->id}/download")
        ->assertOk()
        ->assertJsonPath('data.download_state', 'available');

    $gone = catalogVideo($channel, 'tv-gone', ['is_unavailable' => true]);
    $this->postJson("/api/videos/{$gone->id}/download")->assertUnprocessable();
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

it('lists the current download and the queue in the order the worker takes them', function (): void {
    $auto = onDemandChannel('auto', onDemand: false);
    $onDemand = onDemandChannel('lazy');

    $running = catalogVideo($auto, 'q-running', ['published_at' => now()->subYear()]);
    $autoOld = catalogVideo($auto, 'q-auto-old', ['published_at' => now()->subMonth()]);
    $autoNew = catalogVideo($auto, 'q-auto-new', ['published_at' => now()->subDay()]);
    $requestedLate = catalogVideo($onDemand, 'q-req-late', ['download_requested_at' => now()->subMinute()]);
    $requestedEarly = catalogVideo($onDemand, 'q-req-early', ['download_requested_at' => now()->subHour()]);
    catalogVideo($onDemand, 'q-catalog');
    catalogVideo($auto, 'q-done', ['is_downloaded' => true, 'file_size' => 10]);

    VideoDownloadRun::query()->create(['video_id' => $running->id, 'started_at' => now()->subMinutes(2), 'status' => 'running']);

    $this->getJson('/downloads')
        ->assertOk()
        ->assertJsonPath('current.id', $running->id)
        ->assertJsonPath('current.download_state', 'downloading')
        ->assertJsonPath('current.channel.id', $auto->id)
        ->assertJsonPath('queue.*.id', [$requestedEarly->id, $requestedLate->id, $autoOld->id, $autoNew->id])
        ->assertJsonPath('queue.0.download_state', 'queued')
        ->assertJsonPath('queued_count', 4);
});

it('ignores a stale run and shares the queue size with every page', function (): void {
    $auto = onDemandChannel('auto2', onDemand: false);
    $video = catalogVideo($auto, 'stale-1');
    VideoDownloadRun::query()->create(['video_id' => $video->id, 'started_at' => now()->subDay(), 'status' => 'running']);

    $this->getJson('/downloads')
        ->assertOk()
        ->assertJsonPath('current', null)
        ->assertJsonPath('queue.*.id', [$video->id])
        ->assertJsonPath('queued_count', 1);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('downloads.queued', 1)
            ->where('downloads.active', false));
});

it('pages through the complete queue and keeps videos waiting for a retry visible', function (): void {
    $lastFailure = now('UTC')->startOfSecond()->subMinute();
    $auto = onDemandChannel('paged', onDemand: false);
    $failed = catalogVideo($auto, 'retry', ['published_at' => now()->subYear()]);
    VideoDownloadRun::query()->create([
        'video_id' => $failed->id, 'status' => 'failed', 'started_at' => $lastFailure->copy()->subMinute(), 'finished_at' => $lastFailure,
    ]);
    foreach (range(1, 20) as $index) {
        catalogVideo($auto, "ready-{$index}");
    }

    $this->get('/downloads')->assertInertia(fn (Assert $page) => $page
        ->component('downloads')->has('snapshot.queue', 20)->where('snapshot.queued_count', 21));
    $this->getJson('/downloads?page=2')->assertJsonPath('page', 2)
        ->assertJsonCount(1, 'queue')->assertJsonPath('queue.0.id', $failed->id)
        ->assertJsonPath('queue.0.retry_pending', true)
        ->assertJsonPath('queue.0.retry_at', $lastFailure->copy()->addMinutes(15)->toISOString());
});
