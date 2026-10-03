<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
function useMediaDisk(): string
{
    $mediaRoot = storage_path('framework/testing/media');

    File::deleteDirectory($mediaRoot);
    File::ensureDirectoryExists($mediaRoot);

    Config::set('filesystems.disks.media.root', $mediaRoot);
    Storage::forgetDisk('media');

    return $mediaRoot;
}

function makeChannelWithVideo(string $slug = 'channel'): array
{
    $channel = Channel::query()->create([
        'external_id' => "UC-{$slug}",
        'username' => $slug,
        'name' => "Channel {$slug}",
        'thumbnail' => "thumbnails/channels/UC-{$slug}.jpg",
    ]);

    $downloaded = Video::withoutGlobalScopes()->create([
        'external_id' => "{$slug}-downloaded",
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 0,
        'file_size' => 1024,
        'name' => 'Downloaded video',
        'thumbnail' => "thumbnails/videos/{$slug}-downloaded.jpg",
    ]);

    $pending = Video::withoutGlobalScopes()->create([
        'external_id' => "{$slug}-pending",
        'channel_id' => $channel->id,
        'is_downloaded' => false,
        'duration_seconds' => 60,
        'view_count' => 0,
        'name' => 'Pending video',
        'thumbnail' => "thumbnails/videos/{$slug}-pending.jpg",
    ]);

    Storage::disk('public')->put($channel->getRawOriginal('thumbnail'), 'channel-thumb');
    Storage::disk('public')->put($downloaded->getRawOriginal('thumbnail'), 'video-thumb');
    Storage::disk('public')->put($pending->getRawOriginal('thumbnail'), 'video-thumb');

    return [$channel, $downloaded, $pending];
}
