<?php

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Config::set('services.worker.hook_token', 'secret-token');
    $this->channel = Channel::query()->create(['external_id' => 'UC-hook', 'name' => 'Hook channel', 'thumbnail' => 'thumbnails/channels/UC-hook.jpg']);
});

function hookVideo(Channel $channel, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => 'hook-'.uniqid(),
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 600,
        'view_count' => 3,
        'name' => 'Готовое видео',
        'thumbnail' => 'thumbnails/videos/hook-1.jpg',
        'downloaded_at' => now(),
    ], $attributes));
}

it('notifies the library owner when the worker reports a download', function (): void {
    Notification::fake();
    $video = hookVideo($this->channel);

    $this->withToken('secret-token')
        ->postJson("/api/internal/videos/{$video->id}/downloaded")
        ->assertNoContent();

    Notification::assertSentTo(User::owner(), VideoReady::class, fn (VideoReady $notification) => $notification->video->is($video));
});

it('rejects the hook without the right token, or when no token is configured', function (): void {
    Notification::fake();
    $video = hookVideo($this->channel);

    $this->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();
    $this->withToken('wrong')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();

    Config::set('services.worker.hook_token', null);
    $this->withToken('')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();

    Notification::assertNothingSent();
});

it('does not announce a video that is not downloaded', function (): void {
    Notification::fake();
    $video = hookVideo($this->channel, ['is_downloaded' => false, 'downloaded_at' => null]);

    $this->withToken('secret-token')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertNotFound();

    Notification::assertNothingSent();
});

it('stores the notification and broadcasts it right away on a public channel', function (): void {
    $video = hookVideo($this->channel);
    $notification = new VideoReady($video);
    $owner = User::owner();

    expect($notification->via($owner))->toBe(['database', 'broadcast'])
        ->and($notification->broadcastOn()[0]->name)->toBe('notifications')
        ->and($notification->broadcastAs())->toBe('notification.created')
        // Без очереди: воркера очереди в стеке нет.
        ->and($notification->toBroadcast($owner)->connection)->toBe('sync')
        ->and($notification->toArray($owner))->toMatchArray([
            'video_id' => $video->id,
            'title' => 'Готовое видео',
            'thumbnail' => '/storage/thumbnails/videos/hook-1.jpg',
            'channel_name' => 'Hook channel',
        ]);
});

it('lists notifications for the bell and marks them read', function (): void {
    $owner = User::owner();
    $first = hookVideo($this->channel, ['name' => 'Первое']);
    $second = hookVideo($this->channel, ['name' => 'Второе']);
    $owner->notifyNow(new VideoReady($first), ['database']);
    $this->travel(1)->minutes();
    $owner->notifyNow(new VideoReady($second), ['database']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('unreadNotifications', 2));

    $response = $this->getJson('/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 2)
        // Свежие первыми, данные — на верхнем уровне, как в событии Reverb.
        ->assertJsonPath('data.0.title', 'Второе')
        ->assertJsonPath('data.0.type', 'video-ready')
        ->assertJsonPath('data.0.video_id', $second->id)
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.1.title', 'Первое');

    $this->patchJson('/notifications/'.$response->json('data.0.id'))->assertNoContent();
    $this->getJson('/notifications')->assertJsonPath('unread_count', 1);

    $this->postJson('/notifications/read')->assertNoContent();
    $this->getJson('/notifications')->assertJsonPath('unread_count', 0)->assertJsonCount(2, 'data');

    $this->deleteJson('/notifications')->assertNoContent();
    $this->getJson('/notifications')->assertJsonCount(0, 'data');
});

it('keeps a single owner however often it is asked for', function (): void {
    expect(User::owner()->is(User::owner()))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});
