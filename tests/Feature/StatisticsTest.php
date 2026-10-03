<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function makeChannel(string $slug, string $name): Channel
{
    return Channel::query()->create([
        'external_id' => $slug,
        'username' => $slug,
        'name' => $name,
    ]);
}

function makeVideo(Channel $channel, string $slug, array $attributes = []): Video
{
    return Video::withoutGlobalScopes()->create(array_merge([
        'external_id' => $slug,
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 0,
        'view_count' => 0,
        'name' => $slug,
    ], $attributes));
}

it('returns aggregate statistics for all videos including in-progress items', function (): void {
    $firstChannel = Channel::query()->create([
        'external_id' => 'channel-stats-1',
        'username' => 'channel-stats-1',
        'name' => 'First channel',
    ]);

    $secondChannel = Channel::query()->create([
        'external_id' => 'channel-stats-2',
        'username' => 'channel-stats-2',
        'name' => 'Second channel',
    ]);

    Video::withoutGlobalScopes()->create([
        'external_id' => 'video-stats-1',
        'channel_id' => $firstChannel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'file_size' => 1_024,
        'view_count' => 0,
        'name' => 'Downloaded video',
    ]);

    Video::withoutGlobalScopes()->create([
        'external_id' => 'video-stats-2',
        'channel_id' => $secondChannel->id,
        'is_downloaded' => false,
        'duration_seconds' => 120,
        'file_size' => 2_048,
        'view_count' => 0,
        'name' => 'Queued video',
    ]);

    $response = $this->getJson('/api/statistics');

    $response
        ->assertOk()
        ->assertExactJson([
            'total_videos' => 2,
            'total_channels' => 2,
            'total_video_size' => 3_072,
            'total_video_size_gb' => 0.0,
            'total_duration_seconds' => 180,
            'total_duration_hours' => 0.05,
            'videos_in_progress' => 1,
        ]);
});

it('returns channels sorted by total size desc with zero-video channels included', function (): void {
    $channelA = makeChannel('channel-a', 'Channel A');
    $channelB = makeChannel('channel-b', 'Channel B');
    $channelC = makeChannel('channel-c', 'Channel C');

    makeVideo($channelA, 'a-1', ['file_size' => 5]);
    makeVideo($channelA, 'a-2', ['file_size' => 3]);
    makeVideo($channelC, 'c-1', ['file_size' => 10]);

    $data = $this->getJson('/api/statistics/channels')->assertOk()->json();

    expect($data)->toHaveCount(3);

    expect($data[0]['channel_id'])->toBe($channelC->id);
    expect($data[0]['channel_title'])->toBe('Channel C');
    expect($data[0]['video_count'])->toBe(1);
    expect($data[0]['total_size_bytes'])->toBe(10);

    expect($data[1]['channel_id'])->toBe($channelA->id);
    expect($data[1]['video_count'])->toBe(2);
    expect($data[1]['total_size_bytes'])->toBe(8);

    expect($data[2]['channel_id'])->toBe($channelB->id);
    expect($data[2]['video_count'])->toBe(0);
    expect($data[2]['total_size_bytes'])->toBe(0);
});

it('excludes in-progress videos from channels aggregation', function (): void {
    $channel = makeChannel('channel-mixed', 'Mixed channel');

    makeVideo($channel, 'downloaded-1', ['file_size' => 4]);
    makeVideo($channel, 'in-progress-1', ['is_downloaded' => false, 'file_size' => null]);

    $data = $this->getJson('/api/statistics/channels')->assertOk()->json();

    expect($data)->toHaveCount(1);
    expect($data[0]['video_count'])->toBe(1);
    expect($data[0]['total_size_bytes'])->toBe(4);
});

it('returns 14 zero-filled daily buckets in UTC order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-19 10:00:00', 'UTC'));

    $channel = makeChannel('channel-daily', 'Daily channel');

    makeVideo($channel, 'today', ['file_size' => 100, 'downloaded_at' => Carbon::parse('2026-05-19 09:00:00', 'UTC')]);
    makeVideo($channel, 'five-days-ago', ['file_size' => 50, 'downloaded_at' => Carbon::parse('2026-05-14 12:00:00', 'UTC')]);
    makeVideo($channel, 'thirteen-days-ago', ['file_size' => 25, 'downloaded_at' => Carbon::parse('2026-05-06 00:00:00', 'UTC')]);

    $data = $this->getJson('/api/statistics/daily')->assertOk()->json();

    expect($data)->toHaveCount(14);
    expect($data[0]['date'])->toBe('2026-05-06');
    expect($data[13]['date'])->toBe('2026-05-19');

    $byDate = collect($data)->keyBy('date');

    expect($byDate['2026-05-06']['video_count'])->toBe(1);
    expect($byDate['2026-05-06']['total_size_bytes'])->toBe(25);
    expect($byDate['2026-05-14']['video_count'])->toBe(1);
    expect($byDate['2026-05-14']['total_size_bytes'])->toBe(50);
    expect($byDate['2026-05-19']['video_count'])->toBe(1);
    expect($byDate['2026-05-19']['total_size_bytes'])->toBe(100);

    expect($byDate['2026-05-10']['video_count'])->toBe(0);
    expect($byDate['2026-05-10']['total_size_bytes'])->toBe(0);

    Carbon::setTestNow();
});

it('ignores videos downloaded before the 14-day window', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-19 10:00:00', 'UTC'));

    $channel = makeChannel('channel-window', 'Window channel');

    makeVideo($channel, 'outside-window', ['file_size' => 99, 'downloaded_at' => Carbon::parse('2026-05-04 12:00:00', 'UTC')]);
    makeVideo($channel, 'inside-window', ['file_size' => 7, 'downloaded_at' => Carbon::parse('2026-05-19 08:00:00', 'UTC')]);

    $data = $this->getJson('/api/statistics/daily')->assertOk()->json();

    expect(collect($data)->sum('video_count'))->toBe(1);
    expect(collect($data)->sum('total_size_bytes'))->toBe(7);

    Carbon::setTestNow();
});

it('ignores in-progress videos in daily aggregation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-19 10:00:00', 'UTC'));

    $channel = makeChannel('channel-progress', 'Progress channel');

    makeVideo($channel, 'in-progress', ['is_downloaded' => false, 'file_size' => null, 'downloaded_at' => null]);
    makeVideo($channel, 'downloaded', ['file_size' => 12, 'downloaded_at' => Carbon::parse('2026-05-19 08:00:00', 'UTC')]);

    $data = $this->getJson('/api/statistics/daily')->assertOk()->json();

    expect(collect($data)->sum('video_count'))->toBe(1);
    expect(collect($data)->sum('total_size_bytes'))->toBe(12);

    Carbon::setTestNow();
});
