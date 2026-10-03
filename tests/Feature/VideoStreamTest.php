<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('delegates video streaming to nginx from the media disk', function (): void {
    $mediaRoot = storage_path('framework/testing/media');
    Config::set('filesystems.disks.media.root', $mediaRoot);
    Storage::forgetDisk('media');

    $channel = Channel::query()->create([
        'external_id' => 'channel-stream-test',
        'username' => 'channel-stream-test',
        'name' => 'Channel',
    ]);

    $video = Video::query()->create([
        'external_id' => 'video-stream-test',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 0,
        'name' => 'Range test',
    ]);

    File::ensureDirectoryExists($mediaRoot."/videos/{$channel->id}");
    File::put(
        $mediaRoot."/videos/{$channel->id}/{$video->id}.mp4",
        hex2bin('00000018667479706d703432000000006d70343269736f6d')
    );

    $response = $this->get("/api/videos/{$video->id}/stream");

    $response->assertOk();
    $response->assertHeader('accept-ranges', 'bytes');
    $response->assertHeader('content-type', 'video/mp4');
    $response->assertHeader('x-accel-redirect', "/_protected_media/videos/{$channel->id}/{$video->id}.mp4");
});

it('returns only downloaded videos from the api', function (): void {
    $channel = Channel::query()->create([
        'external_id' => 'channel-ready-filter',
        'username' => 'channel-ready-filter',
        'name' => 'Channel',
    ]);

    $readyVideo = Video::withoutGlobalScopes()->create([
        'external_id' => 'video-ready',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 0,
        'name' => 'Ready video',
    ]);

    Video::withoutGlobalScopes()->create([
        'external_id' => 'video-processing',
        'channel_id' => $channel->id,
        'is_downloaded' => false,
        'duration_seconds' => 60,
        'view_count' => 0,
        'name' => 'Processing video',
    ]);

    $response = $this->getJson('/api/videos');

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $readyVideo->id);
});

it('orders videos by published_at and exposes published and download timestamps', function (): void {
    $channel = Channel::query()->create([
        'external_id' => 'channel-published-order',
        'username' => 'channel-published-order',
        'name' => 'Channel',
    ]);

    $olderPublishedAt = Carbon::parse('2026-04-10 10:00:00', 'UTC');
    $newerPublishedAt = Carbon::parse('2026-04-12 10:00:00', 'UTC');
    $downloadedAt = Carbon::parse('2026-04-13 08:30:00', 'UTC');
    $createdAt = Carbon::parse('2026-04-11 10:00:00', 'UTC');

    Video::withoutGlobalScopes()->create([
        'external_id' => 'video-older',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 10,
        'name' => 'Older video',
        'created_at' => Carbon::parse('2026-04-13 10:00:00', 'UTC'),
        'published_at' => $olderPublishedAt,
    ]);

    $newerVideo = Video::withoutGlobalScopes()->create([
        'external_id' => 'video-newer',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 20,
        'name' => 'Newer video',
        'created_at' => $createdAt,
        'published_at' => $newerPublishedAt,
        'downloaded_at' => $downloadedAt,
    ]);

    $response = $this->getJson('/api/videos');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $newerVideo->id)
        ->assertJsonPath('data.0.created_at', $createdAt->toJSON())
        ->assertJsonPath('data.0.published_at', $newerPublishedAt->toJSON())
        ->assertJsonPath('data.0.downloaded_at', $downloadedAt->toJSON());
});
