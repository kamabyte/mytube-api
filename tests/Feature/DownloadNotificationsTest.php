<?php

use App\Events\VideoDownloaded;
use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('services.worker.hook_token', 'secret-token');
    $this->channel = Channel::query()->create(['external_id' => 'UC-hook', 'name' => 'Hook channel', 'thumbnail' => 'thumbnails/channels/UC-hook.jpg']);
});

function hookVideo(Channel $channel, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => 'hook-1',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 600,
        'view_count' => 3,
        'name' => 'Готовое видео',
        'thumbnail' => 'thumbnails/videos/hook-1.jpg',
        'downloaded_at' => now(),
    ], $attributes));
}

it('broadcasts a downloaded video when the worker calls the hook', function (): void {
    Event::fake([VideoDownloaded::class]);
    $video = hookVideo($this->channel);

    $this->withToken('secret-token')
        ->postJson("/api/internal/videos/{$video->id}/downloaded")
        ->assertNoContent();

    Event::assertDispatched(VideoDownloaded::class, fn (VideoDownloaded $event) => $event->video->is($video));
});

it('rejects the hook without the right token, or when no token is configured', function (): void {
    Event::fake([VideoDownloaded::class]);
    $video = hookVideo($this->channel);

    $this->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();
    $this->withToken('wrong')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();

    Config::set('services.worker.hook_token', null);
    $this->withToken('')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertUnauthorized();

    Event::assertNotDispatched(VideoDownloaded::class);
});

it('does not announce a video that is not downloaded', function (): void {
    Event::fake([VideoDownloaded::class]);
    $video = hookVideo($this->channel, ['is_downloaded' => false, 'downloaded_at' => null]);

    $this->withToken('secret-token')->postJson("/api/internal/videos/{$video->id}/downloaded")->assertNotFound();

    Event::assertNotDispatched(VideoDownloaded::class);
});

it('broadcasts a card with the thumbnail, title, channel and a public channel', function (): void {
    $event = new VideoDownloaded(hookVideo($this->channel));

    expect($event->broadcastOn()->name)->toBe('downloads')
        ->and($event->broadcastAs())->toBe('video.downloaded')
        ->and($event->broadcastWith()['video'])
        ->toMatchArray([
            'name' => 'Готовое видео',
            'thumbnail' => '/storage/thumbnails/videos/hook-1.jpg',
        ])
        ->and($event->broadcastWith()['video']['channel']['name'])->toBe('Hook channel');
});
