<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function tvChannel(string $slug, array $attributes = []): Channel
{
    return Channel::query()->create(array_merge([
        'external_id' => "UC-tv-{$slug}",
        'username' => "tv-{$slug}",
        'name' => "TV channel {$slug}",
        'thumbnail' => "thumbnails/channels/UC-tv-{$slug}.jpg",
    ], $attributes));
}

function tvVideo(Channel $channel, string $slug, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => "tv-{$slug}",
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 120,
        'view_count' => 10,
        'file_size' => 2048,
        'name' => "TV video {$slug}",
        'description' => "Description {$slug}",
        'thumbnail' => "thumbnails/videos/tv-{$slug}.jpg",
        'published_at' => now(),
    ], $attributes));
}

/**
 * @return list<int>
 */
function tvIds(array $items): array
{
    return array_map(fn (array $item) => $item['id'], $items);
}

it('sorts the video list by the shared keys with a stable id tiebreak across pages', function (): void {
    $channel = tvChannel('sort');
    $same = Carbon::parse('2026-05-01 10:00:00');

    $a = tvVideo($channel, 'a', ['published_at' => $same, 'view_count' => 5, 'downloaded_at' => now()->subDays(3)]);
    $b = tvVideo($channel, 'b', ['published_at' => $same, 'view_count' => 5, 'downloaded_at' => null]);
    $c = tvVideo($channel, 'c', ['published_at' => $same, 'view_count' => 5, 'downloaded_at' => now()->subDay()]);
    $d = tvVideo($channel, 'd', ['published_at' => $same->copy()->addDay(), 'view_count' => 50, 'downloaded_at' => now()->subDays(2)]);
    tvVideo($channel, 'pending', ['is_downloaded' => false, 'published_at' => now()->addYear(), 'view_count' => 999]);

    $pages = fn (string $sort) => [
        ...$this->getJson("/api/videos?sort={$sort}&page[size]=2&page[number]=1")->assertOk()->json('data'),
        ...$this->getJson("/api/videos?sort={$sort}&page[size]=2&page[number]=2")->assertOk()->json('data'),
    ];

    expect(tvIds($pages('new')))->toBe([$d->id, $c->id, $b->id, $a->id])
        ->and(tvIds($pages('old')))->toBe([$a->id, $b->id, $c->id, $d->id])
        ->and(tvIds($pages('popular')))->toBe([$d->id, $c->id, $b->id, $a->id])
        ->and(tvIds($pages('added')))->toBe([$c->id, $d->id, $a->id, $b->id])
        // Префикс «-» у этих ключей ничего не меняет.
        ->and(tvIds($pages('-new')))->toBe([$d->id, $c->id, $b->id, $a->id]);

    // Старые сортировки и сортировка по умолчанию на месте.
    $this->getJson('/api/videos?sort=-view_count')->assertOk()->assertJsonPath('data.0.id', $d->id);
    $this->getJson('/api/videos')->assertOk()->assertJsonPath('data.0.id', $d->id)->assertJsonCount(4, 'data');
    $this->getJson('/api/videos?sort=bogus')->assertStatus(400);
});

it('exposes view_count, is_playlist and videos_count and filters channels by is_playlist', function (): void {
    $channel = tvChannel('regular');
    tvVideo($channel, 'r-1', ['view_count' => 42]);
    tvVideo($channel, 'r-2');
    tvVideo($channel, 'r-pending', ['is_downloaded' => false]);
    $playlist = tvChannel('playlist', ['is_playlist' => true, 'name' => 'A playlist']);

    $this->getJson('/api/videos?sort=popular')
        ->assertOk()
        ->assertJsonPath('data.0.view_count', 42);

    $this->getJson('/api/channels?sort=name')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $playlist->id)
        ->assertJsonPath('data.0.is_playlist', true)
        ->assertJsonPath('data.0.videos_count', 0)
        ->assertJsonPath('data.1.is_playlist', false)
        ->assertJsonPath('data.1.videos_count', 2);

    foreach (['1', 'true'] as $value) {
        $this->getJson("/api/channels?filter[is_playlist]={$value}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $playlist->id);
    }

    foreach (['0', 'false'] as $value) {
        $this->getJson("/api/channels?filter[is_playlist]={$value}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $channel->id);
    }

    // Существующие параметры продолжают работать, счётчик — вместе с fields[].
    $this->getJson('/api/channels?filter[has_videos]=1&fields[channels]=id,name')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.videos_count', 2);

    $this->getJson("/api/channels/{$playlist->id}")
        ->assertOk()
        ->assertJsonPath('data.is_playlist', true)
        ->assertJsonPath('data.videos_count', 0);
});

it('keeps the channel name sort stable with an id tiebreak', function (): void {
    $first = tvChannel('same-1', ['name' => 'Same']);
    $second = tvChannel('same-2', ['name' => 'Same']);
    $third = tvChannel('same-3', ['name' => 'Same']);

    $ids = [
        ...$this->getJson('/api/channels?sort=name&page[size]=2')->json('data'),
        ...$this->getJson('/api/channels?sort=name&page[size]=2&page[number]=2')->json('data'),
    ];

    expect(tvIds($ids))->toBe([$first->id, $second->id, $third->id]);
});

it('shows a video with its channel and description, and 404s for not downloaded ones', function (): void {
    $channel = tvChannel('show', ['is_playlist' => true]);
    $video = tvVideo($channel, 'show');
    tvVideo($channel, 'show-2');
    $pending = tvVideo($channel, 'show-pending', ['is_downloaded' => false]);

    $this->getJson("/api/videos/{$video->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $video->id)
        ->assertJsonPath('data.description', 'Description show')
        ->assertJsonPath('data.view_count', 10)
        ->assertJsonPath('data.channel.id', $channel->id)
        ->assertJsonPath('data.channel.name', 'TV channel show')
        ->assertJsonPath('data.channel.is_playlist', true)
        ->assertJsonPath('data.channel.videos_count', 2);

    $this->getJson("/api/videos/{$pending->id}")->assertNotFound()->assertJsonStructure(['message']);
    // Без Accept: application/json — тоже JSON.
    $plain = $this->get('/api/videos/999999')->assertNotFound();
    expect($plain->headers->get('content-type'))->toContain('application/json');
});

it('builds the up-next queue from the same channel first, then fresh videos elsewhere', function (): void {
    $channel = tvChannel('queue');
    $same = Carbon::parse('2026-05-01 10:00:00');
    $newest = tvVideo($channel, 'q-newest', ['published_at' => $same->copy()->addDay()]);
    $tieBefore = tvVideo($channel, 'q-tie-before', ['published_at' => $same]);
    $current = tvVideo($channel, 'q-current', ['published_at' => $same]);
    $tieAfter = tvVideo($channel, 'q-tie-after', ['published_at' => $same]);
    $older = tvVideo($channel, 'q-older', ['published_at' => $same->copy()->subDay()]);
    tvVideo($channel, 'q-pending', ['is_downloaded' => false, 'published_at' => $same->copy()->subHour()]);

    $other = tvChannel('queue-other');
    $otherNew = tvVideo($other, 'o-new', ['published_at' => $same->copy()->addDays(5)]);
    $otherOld = tvVideo($other, 'o-old', ['published_at' => $same->copy()->subDays(5)]);
    tvVideo($other, 'o-pending', ['is_downloaded' => false, 'published_at' => $same->copy()->addDays(9)]);

    $response = $this->getJson("/api/videos/{$current->id}/up-next")->assertOk();

    expect(tvIds($response->json('data')))->toBe([$tieBefore->id, $older->id, $otherNew->id, $otherOld->id])
        ->and($response->json('data.0.channel'))->toMatchArray(['id' => $channel->id, 'is_playlist' => false])
        ->and($response->json('data.0'))->not->toHaveKey('description');

    expect(tvIds($this->getJson("/api/videos/{$current->id}/up-next?limit=1")->json('data')))->toBe([$tieBefore->id]);
    expect(tvIds($this->getJson("/api/videos/{$current->id}/up-next?limit=3")->json('data')))
        ->toBe([$tieBefore->id, $older->id, $otherNew->id])
        ->not->toContain($current->id, $newest->id, $tieAfter->id);

    foreach (['0', '31', 'abc'] as $limit) {
        $this->getJson("/api/videos/{$current->id}/up-next?limit={$limit}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
});

it('searches videos and channels case-insensitively, including cyrillic, with pagination', function (): void {
    $channel = tvChannel('search', ['name' => 'Привет канал']);
    $b = tvChannel('search-b', ['name' => 'Привет канал']);
    tvChannel('search-c', ['name' => 'Другой']);

    foreach (range(1, 5) as $i) {
        tvVideo($channel, "s-{$i}", ['name' => "привет мир {$i}", 'published_at' => now()->subDays($i)]);
    }
    tvVideo($channel, 's-pending', ['name' => 'привет скрытый', 'is_downloaded' => false]);
    tvVideo($channel, 's-other', ['name' => 'Something else']);

    $response = $this->getJson('/api/search?q='.urlencode('  ПРИВЕТ ').'&page[size]=2')
        ->assertOk()
        ->assertJsonPath('query', 'ПРИВЕТ')
        ->assertJsonCount(2, 'channels')
        ->assertJsonPath('channels.0.id', $channel->id)
        ->assertJsonPath('channels.1.id', $b->id)
        ->assertJsonPath('channels.0.videos_count', 6)
        ->assertJsonPath('channels.0.is_playlist', false)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'привет мир 1')
        ->assertJsonPath('data.0.channel.name', 'Привет канал')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonStructure(['query', 'channels', 'data', 'meta', 'links']);

    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'thumbnail', 'duration_seconds', 'view_count', 'video_url', 'published_at']);

    $this->getJson('/api/search?q='.urlencode('ПРИВЕТ').'&page[size]=2&page[number]=3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'привет мир 5')
        ->assertJsonPath('meta.current_page', 3);

    // % и _ — обычные символы, а не шаблоны LIKE.
    $this->getJson('/api/search?q=%25')->assertOk()->assertJsonCount(0, 'data');
});

it('answers an empty search with empty results and rejects too long queries', function (): void {
    tvVideo(tvChannel('empty'), 'e-1');

    foreach (['/api/search', '/api/search?q=', '/api/search?q=%20%20'] as $url) {
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('channels', [])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    $this->getJson('/api/search?q='.urlencode(str_repeat('я', 100)))->assertOk();
    $this->getJson('/api/search?q='.urlencode(str_repeat('я', 101)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

it('returns the bounded home aggregate without not-downloaded videos', function (): void {
    foreach (range(1, 14) as $i) {
        $channel = tvChannel("home-{$i}");
        foreach (range(1, 13) as $j) {
            tvVideo($channel, "h-{$i}-{$j}", [
                'published_at' => now()->subDays($i)->subMinutes($j),
                'downloaded_at' => $j === 1 ? now()->subHours($i) : null,
            ]);
        }
    }

    $empty = tvChannel('home-empty');
    tvVideo($empty, 'h-empty-pending', ['is_downloaded' => false, 'published_at' => now()->addDay()]);
    $pending = tvVideo(Channel::query()->first(), 'h-pending', ['is_downloaded' => false, 'published_at' => now()->addDays(2), 'downloaded_at' => now()->addDay()]);

    DB::enableQueryLog();

    $response = $this->getJson('/api/home')
        ->assertOk()
        ->assertJsonStructure([
            'featured' => [['id', 'name', 'thumbnail', 'video_url', 'channel' => ['id', 'name', 'thumbnail', 'is_playlist']]],
            'latest',
            'recently_added',
            'channels' => [['id', 'name', 'thumbnail', 'is_playlist', 'videos_count', 'videos' => [['id', 'channel' => ['id', 'name']]]]],
        ]);

    // Без N+1: число запросов не зависит от числа каналов и видео.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(10);

    $home = $response->json();

    expect($home['latest'][0])->not->toHaveKey('description');

    expect($home['featured'])->toHaveCount(6)
        ->and($home['featured'][0]['name'])->toBe('TV video h-1-1')
        ->and($home['latest'])->toHaveCount(16)
        ->and($home['latest'][0]['name'])->toBe('TV video h-1-1')
        ->and($home['recently_added'])->toHaveCount(16)
        ->and($home['recently_added'][0]['name'])->toBe('TV video h-1-1')
        ->and($home['recently_added'][1]['name'])->toBe('TV video h-2-1')
        ->and($home['channels'])->toHaveCount(12)
        ->and($home['channels'][0]['name'])->toBe('TV channel home-1')
        ->and($home['channels'][0]['videos_count'])->toBe(13)
        ->and($home['channels'][0]['videos'])->toHaveCount(12)
        ->and($home['channels'][0]['videos'][0]['name'])->toBe('TV video h-1-1')
        ->and($home['channels'][0]['videos'][0]['channel']['id'])->toBe($home['channels'][0]['id'])
        ->and(collect($home['channels'])->pluck('id'))->not->toContain($empty->id);

    $allIds = collect([...$home['featured'], ...$home['latest'], ...$home['recently_added']])
        ->merge(collect($home['channels'])->flatMap(fn ($channel) => $channel['videos']))
        ->pluck('id');

    expect($allIds)->not->toContain($pending->id);
});
