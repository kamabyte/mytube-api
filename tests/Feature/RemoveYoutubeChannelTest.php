<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('removes the channel with its videos, thumbnails and downloaded files', function (): void {
    $mediaRoot = useMediaDisk();
    [$channel, $downloaded] = makeChannelWithVideo();

    File::ensureDirectoryExists($mediaRoot."/videos/{$channel->id}");
    File::put($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4", 'video-bytes');
    File::put($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.ru.srt", 'subtitles');

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->toBeNull()
        ->and(Video::withoutGlobalScopes()->where('channel_id', $channel->id)->count())->toBe(0)
        ->and(File::isDirectory($mediaRoot."/videos/{$channel->id}"))->toBeFalse();

    Storage::disk('public')->assertDirectoryEmpty('thumbnails/channels');
    Storage::disk('public')->assertDirectoryEmpty('thumbnails/videos');
});

it('keeps other channels and their files untouched', function (): void {
    $mediaRoot = useMediaDisk();
    [$channel] = makeChannelWithVideo('first');
    [$other, $otherVideo] = makeChannelWithVideo('second');

    File::ensureDirectoryExists($mediaRoot."/videos/{$other->id}");
    File::put($mediaRoot."/videos/{$other->id}/{$otherVideo->id}.mp4", 'video-bytes');

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
        ->assertSuccessful();

    expect(Channel::query()->find($other->id))->not->toBeNull()
        ->and(Video::withoutGlobalScopes()->where('channel_id', $other->id)->count())->toBe(2)
        ->and(File::exists($mediaRoot."/videos/{$other->id}/{$otherVideo->id}.mp4"))->toBeTrue();

    Storage::disk('public')->assertExists($other->getRawOriginal('thumbnail'));
});

it('resolves the channel by username, handle and url', function (string $needle): void {
    useMediaDisk();
    [$channel] = makeChannelWithVideo('handle');

    $this->artisan('youtube:remove-channel', ['channel' => $needle, '--force' => true])
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->toBeNull();
})->with([
    'username' => 'handle',
    'handle' => '@handle',
    'url' => 'https://www.youtube.com/@handle',
    'external id' => 'UC-handle',
]);

it('fails when the channel was not found', function (): void {
    useMediaDisk();

    $this->artisan('youtube:remove-channel', ['channel' => 'missing', '--force' => true])
        ->expectsOutputToContain('was not found')
        ->assertFailed();
});

it('does not remove anything when the confirmation is declined', function (): void {
    $mediaRoot = useMediaDisk();
    [$channel, $downloaded] = makeChannelWithVideo();

    File::ensureDirectoryExists($mediaRoot."/videos/{$channel->id}");
    File::put($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4", 'video-bytes');

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id])
        ->expectsConfirmation(
            'Permanently remove "Channel channel" with 2 video(s) and 11 B of downloaded files?',
            'no',
        )
        ->assertFailed();

    expect(Channel::query()->find($channel->id))->not->toBeNull()
        ->and(File::exists($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4"))->toBeTrue();

    Storage::disk('public')->assertExists($channel->getRawOriginal('thumbnail'));
});

it('reports but keeps everything on a dry run', function (): void {
    $mediaRoot = useMediaDisk();
    [$channel, $downloaded] = makeChannelWithVideo();

    File::ensureDirectoryExists($mediaRoot."/videos/{$channel->id}");
    File::put($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4", 'video-bytes');

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->not->toBeNull()
        ->and(File::exists($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4"))->toBeTrue();
});

it('lets the channel be picked from the list', function (): void {
    useMediaDisk();
    [$channel] = makeChannelWithVideo('picked');

    $this->artisan('youtube:remove-channel')
        ->expectsQuestion('Which channel do you want to remove?', $channel->id)
        ->expectsConfirmation(
            'Permanently remove "Channel picked" with 2 video(s) and 0 B of downloaded files?',
            'yes',
        )
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->toBeNull();
});

it('leaves the channel alone when the picker is cancelled', function (): void {
    useMediaDisk();
    [$channel] = makeChannelWithVideo('cancelled');

    $this->artisan('youtube:remove-channel')
        ->expectsQuestion('Which channel do you want to remove?', 'back')
        ->expectsOutputToContain('Aborted, nothing was removed.')
        ->assertFailed();

    expect(Channel::query()->find($channel->id))->not->toBeNull();
});

it('removes the records but keeps the files when the media disk is unavailable', function (): void {
    Config::set('filesystems.disks.media.root', storage_path('framework/testing/missing-media'));
    Storage::forgetDisk('media');

    [$channel] = makeChannelWithVideo();

    $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
        ->expectsOutputToContain('media disk')
        ->assertSuccessful();

    expect(Channel::query()->find($channel->id))->toBeNull();
});

it('requires the channel argument when it runs non-interactively', function (): void {
    useMediaDisk();
    makeChannelWithVideo();

    $this->artisan('youtube:remove-channel', ['--no-interaction' => true])
        ->expectsOutputToContain('non-interactively')
        ->assertFailed();
});

it('refuses to remove anything when the application is not writable by the current user', function (): void {
    useMediaDisk();
    [$channel] = makeChannelWithVideo();

    $storagePath = storage_path('framework/testing/readonly-storage');
    File::deleteDirectory($storagePath);
    File::ensureDirectoryExists($storagePath.'/logs');
    chmod($storagePath.'/logs', 0555);

    $this->app->useStoragePath($storagePath);

    try {
        $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
            ->expectsOutputToContain('cannot write to')
            ->assertFailed();
    } finally {
        chmod($storagePath.'/logs', 0755);
        File::deleteDirectory($storagePath);
    }

    expect(Channel::query()->find($channel->id))->not->toBeNull();
});

it('reports a failure when the downloaded files could not be deleted', function (): void {
    $mediaRoot = useMediaDisk();
    [$channel, $downloaded] = makeChannelWithVideo();

    File::ensureDirectoryExists($mediaRoot."/videos/{$channel->id}");
    File::put($mediaRoot."/videos/{$channel->id}/{$downloaded->id}.mp4", 'video-bytes');
    chmod($mediaRoot."/videos/{$channel->id}", 0555);

    try {
        $this->artisan('youtube:remove-channel', ['channel' => $channel->id, '--force' => true])
            ->expectsOutputToContain('could not be deleted')
            ->assertFailed();
    } finally {
        chmod($mediaRoot."/videos/{$channel->id}", 0755);
    }

    expect(Channel::query()->find($channel->id))->toBeNull()
        ->and(File::isDirectory($mediaRoot."/videos/{$channel->id}"))->toBeTrue();
});
