<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function webChannel(string $slug, array $attributes = []): Channel
{
    return Channel::query()->create(array_merge([
        'external_id' => "UC-{$slug}",
        'username' => $slug,
        'name' => "Channel {$slug}",
        'thumbnail' => "thumbnails/channels/UC-{$slug}.jpg",
    ], $attributes));
}

function webVideo(Channel $channel, string $slug, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => $slug,
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 120,
        'view_count' => 10,
        'file_size' => 2048,
        'name' => "Video {$slug}",
        'thumbnail' => "thumbnails/videos/{$slug}.jpg",
        'published_at' => now(),
    ], $attributes));
}

it('renders the home page with featured videos and channel shelves', function (): void {
    $channel = webChannel('home');
    webVideo($channel, 'old', ['published_at' => now()->subDays(3)]);
    webVideo($channel, 'new', ['published_at' => now()->subDay()]);
    webVideo($channel, 'pending', ['is_downloaded' => false]);
    webChannel('empty');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('featured', 2)
            ->has('latest', 2)
            ->where('latest.0.name', 'Video new')
            ->where('latest.0.channel.name', 'Channel home')
            // Каналы без скачанных видео на главной не показываются.
            ->has('shelves', 1)
            ->has('shelves.0.videos', 2)
            ->where('shelves.0.videos_count', 2)
            ->has('sidebarChannels', 1));
});

it('serves thumbnails and the stream by host-relative urls', function (): void {
    $video = webVideo(webChannel('urls'), 'urls');

    $this->get("/watch/{$video->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('watch')
            ->where('video.thumbnail', '/storage/thumbnails/videos/urls.jpg')
            ->where('video.stream_url', "/api/videos/{$video->id}/stream")
            ->where('channel.thumbnail', '/storage/thumbnails/channels/UC-urls.jpg'));
});

it('queues older videos of the same channel first, then fills from other channels', function (): void {
    $channel = webChannel('queue');
    $newest = webVideo($channel, 'q-newest', ['published_at' => now()]);
    $current = webVideo($channel, 'q-current', ['published_at' => now()->subDay()]);
    $older = webVideo($channel, 'q-older', ['published_at' => now()->subDays(2)]);
    $other = webVideo(webChannel('other'), 'o-1', ['published_at' => now()->subHour()]);

    $this->get("/watch/{$current->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('watch')
            ->has('upNext', 2)
            ->where('upNext.0.id', $older->id)
            ->where('upNext.1.id', $other->id));

    expect($newest->id)->not->toBe($older->id);
});

it('builds the up-next queue around videos without a publish date', function (): void {
    $channel = webChannel('undated');
    $dated = webVideo($channel, 'u-dated', ['published_at' => now()->subDay()]);
    $undatedFirst = webVideo($channel, 'u-undated-1', ['published_at' => null]);
    $undatedSecond = webVideo($channel, 'u-undated-2', ['published_at' => null]);

    // Недатированные идут после всех датированных, между собой — по id.
    $this->get("/watch/{$undatedSecond->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upNext.0.id', $undatedFirst->id)
            ->has('upNext', 1));

    $this->get("/watch/{$dated->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upNext.0.id', $undatedSecond->id)
            ->where('upNext.1.id', $undatedFirst->id));
});

it('does not open videos that are not downloaded yet', function (): void {
    $pending = webVideo(webChannel('pending'), 'pending', ['is_downloaded' => false]);

    $this->get("/watch/{$pending->id}")->assertNotFound();
});

it('paginates the feed for infinite scroll and honours the sort', function (): void {
    $channel = webChannel('feed');
    foreach (range(1, 30) as $i) {
        webVideo($channel, "feed-{$i}", ['view_count' => $i, 'published_at' => now()->subDays($i)]);
    }

    $this->get('/videos')
        ->assertInertia(fn (Assert $page) => $page
            ->component('feed')
            ->where('sort', 'new')
            ->has('videos.data', 24)
            ->where('videos.data.0.name', 'Video feed-1')
            ->where('videos.meta.total', 30));

    $this->get('/videos?sort=popular&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', 'popular')
            ->has('videos.data', 6)
            ->where('videos.data.0.view_count', 6));

    $this->get('/videos?sort=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('sort', 'new'));
});

it('shows a channel with its aggregates and videos', function (): void {
    $channel = webChannel('page');
    webVideo($channel, 'p-1', ['duration_seconds' => 100, 'file_size' => 1000]);
    webVideo($channel, 'p-2', ['duration_seconds' => 200, 'file_size' => 3000]);
    webVideo($channel, 'p-3', ['is_downloaded' => false, 'file_size' => 99999]);

    $this->get("/channels/{$channel->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channel')
            ->where('channel.videos_count', 2)
            ->where('channel.total_size_bytes', 4000)
            ->where('channel.total_duration_seconds', 300)
            ->has('videos.data', 2));
});

it('lists channels in the library', function (): void {
    webVideo(webChannel('lib-a'), 'lib-a-1');
    webChannel('lib-b');

    $this->get('/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels')
            ->has('channels', 2)
            ->where('channels.0.name', 'Channel lib-a')
            ->where('channels.0.videos_count', 1));
});

it('searches videos and channels case-insensitively, including cyrillic', function (): void {
    $channel = webChannel('search', ['name' => 'Грузовичок Лёва']);
    webVideo($channel, 's-1', ['name' => 'Мультик про Экскаватор']);
    webVideo($channel, 's-2', ['name' => 'Something else']);

    $this->get('/search?q='.urlencode('экскаватор'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('search')
            ->where('query', 'экскаватор')
            ->has('videos.data', 1)
            ->where('videos.data.0.name', 'Мультик про Экскаватор')
            ->has('channels', 0));

    $this->get('/search?q='.urlencode('ГРУЗОВИЧОК'))
        ->assertInertia(fn (Assert $page) => $page->has('channels', 1)->has('videos.data', 0));

    // % и _ — обычные символы, а не шаблоны LIKE.
    $this->get('/search?q=%25')
        ->assertInertia(fn (Assert $page) => $page->has('videos.data', 0));
});

it('renders an empty search page without a query', function (): void {
    $this->get('/search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('search')->where('query', '')->where('videos', null));
});

it('renders statistics', function (): void {
    webVideo(webChannel('stats'), 'st-1', ['downloaded_at' => now()]);

    $this->get('/statistics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stats')
            ->where('summary.total_videos', 1)
            ->has('channels', 1)
            ->has('daily', 14));
});

it('serves the JSON API under /api and the web pages at the root', function (): void {
    webVideo(webChannel('api'), 'api-1');

    $this->getJson('/api/channels')->assertOk()->assertJsonStructure(['data']);
    $this->getJson('/api/videos')->assertOk()->assertJsonStructure(['data' => [['video_url']]]);
    $this->getJson('/api/statistics')->assertOk()->assertJsonStructure(['total_videos']);

    expect($this->getJson('/api/videos')->json('data.0.video_url'))->toEndWith('/api/videos/'.Video::query()->value('id').'/stream');

    $this->get('/videos')->assertOk()->assertInertia(fn (Assert $page) => $page->component('feed'));
    $this->get('/channels')->assertOk()->assertInertia(fn (Assert $page) => $page->component('channels'));
    $this->get('/statistics')->assertOk()->assertInertia(fn (Assert $page) => $page->component('stats'));
});

it('looks up videos for continue watching, skipping removed and missing ones', function (): void {
    $channel = webChannel('lookup');
    $kept = webVideo($channel, 'kept');
    $removed = webVideo($channel, 'removed', [
        'is_downloaded' => false,
        'is_unavailable' => true,
        'removed_at' => now(),
    ]);

    $this->getJson('/videos/lookup?'.http_build_query(['ids' => [$kept->id, $removed->id, 999999, 'nope']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id)
        ->assertJsonPath('data.0.channel.name', 'Channel lookup');

    $this->getJson('/videos/lookup')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('reports videos downloaded since the cursor for in-tab notifications', function (): void {
    $this->travelTo('2026-10-04 12:00:00');
    $channel = webChannel('notify');
    webVideo($channel, 'before', ['downloaded_at' => '2026-10-04 11:00:00']);
    webVideo($channel, 'after-1', ['downloaded_at' => '2026-10-04 11:30:00']);
    webVideo($channel, 'after-2', ['downloaded_at' => '2026-10-04 11:45:00']);
    webVideo($channel, 'pending', ['is_downloaded' => false]);

    // Первый заход: только курсор, старое не всплывает.
    $this->getJson('/videos/downloaded')
        ->assertOk()
        ->assertExactJson(['now' => '2026-10-04T12:00:00+00:00', 'total' => 0, 'data' => []]);

    $this->getJson('/videos/downloaded?since='.urlencode('2026-10-04T11:30:00+00:00'))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('data.0.name', 'Video after-2')
        ->assertJsonPath('data.0.channel.name', 'Channel notify')
        ->assertJsonPath('data.1.name', 'Video after-1');

    // Курсор в другом часовом поясе — тот же момент.
    $this->getJson('/videos/downloaded?since='.urlencode('2026-10-04T14:40:00+03:00'))->assertJsonPath('total', 1);

    $this->getJson('/videos/downloaded?since=garbage')->assertJsonPath('total', 0);
});

it('caps notification cards and looks back at most a week', function (): void {
    $this->travelTo('2026-10-04 12:00:00');
    $channel = webChannel('burst');
    foreach (range(1, 8) as $i) {
        webVideo($channel, "burst-{$i}", ['downloaded_at' => '2026-10-04 11:00:00']);
    }
    webVideo($channel, 'ancient', ['downloaded_at' => '2026-09-01 00:00:00']);

    $this->getJson('/videos/downloaded?since='.urlencode('2020-01-01T00:00:00Z'))
        ->assertJsonPath('total', 8)
        ->assertJsonCount(5, 'data');
});
