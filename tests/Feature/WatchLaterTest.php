<?php

use App\Models\Channel;
use App\Models\Video;
use Google\Service\YouTube;
use Google\Service\YouTube\Resource\Videos as VideosResource;
use Google\Service\YouTube\VideoListResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->cookieFile = tempnam(sys_get_temp_dir(), 'mytube-watch-later-test-');
    file_put_contents($this->cookieFile, '# Netscape HTTP Cookie File');
    config([
        'services.youtube.watch_later_enabled' => true,
        'services.youtube.watch_later_cookies' => $this->cookieFile,
    ]);
});

afterEach(function (): void {
    unlink($this->cookieFile);
});

it('imports ids through the normal catalog and retains videos removed from Watch Later', function (): void {
    Storage::fake('public');
    Http::fake(['*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $originalChannel = Channel::create([
        'external_id' => 'UC-original',
        'name' => 'Original channel',
        'download_on_demand' => true,
    ]);
    $existing = Video::create([
        'channel_id' => $originalChannel->id,
        'name' => 'Existing video',
        'external_id' => 'olderVideo1',
        'is_downloaded' => true,
        'removed_at' => null,
    ]);
    $resources = Mockery::mock(VideosResource::class);
    $resources->shouldReceive('listVideos')->once()
        ->with('snippet,contentDetails,statistics,liveStreamingDetails', ['id' => 'olderVideo1,olderVideo2'])
        ->andReturn(new VideoListResponse(['items' => array_map(fn (string $id) => [
            'id' => $id,
            'snippet' => [
                'title' => $id,
                'description' => '',
                'publishedAt' => '2020-01-01T00:00:00Z',
                'liveBroadcastContent' => 'none',
                'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/thumbnail.jpg']],
            ],
            'contentDetails' => ['duration' => 'PT25M'],
        ], ['olderVideo1', 'olderVideo2'])]));

    $youTube = Mockery::mock(YouTube::class);
    $youTube->videos = $resources;
    app()->instance(YouTube::class, $youTube);

    $runs = 0;
    Process::fake(function (PendingProcess $process) use (&$runs) {
        $args = $process->command;
        $copy = $args[array_search('--cookies', $args, true) + 1];

        expect($args)->toContain('yt-dlp', '--flat-playlist', '--skip-download', ':ytwatchlater')
            ->and($copy)->not->toBe($this->cookieFile)
            ->and(file_get_contents($copy))->toBe(file_get_contents($this->cookieFile));

        return Process::result($runs++ === 0 ? "olderVideo1\nolderVideo2\nolderVideo1\ninvalid\n" : "olderVideo1\n");
    });

    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertSuccessful();
    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertSuccessful();
    $source = Channel::where('external_id', Channel::WATCH_LATER_ID)->sole();

    expect($source->videos()->withoutGlobalScopes()->count())->toBe(2)
        ->and(Video::catalog()->count())->toBe(2)
        ->and($existing->refresh()->channel_id)->toBe($originalChannel->id)
        ->and(Video::catalog()->awaitingDownload()->sole()->external_id)->toBe('olderVideo2');

    $this->get('/watch-later')->assertInertia(fn (Assert $page) => $page
        ->component('watch-later')
        ->where('enabled', true)
        ->where('syncError', null)
        ->has('videos.data', 2)
        ->where('videos.data.0.download_state', 'queued')
        ->where('videos.data.0.channel.is_watch_later', true)
        ->has('sidebarChannels', 1));

    $this->get('/channels')->assertInertia(fn (Assert $page) => $page->has('channels', 1));
    $this->get("/channels/{$source->id}")->assertRedirect('/watch-later');
});

it('reports a failed session without exposing yt-dlp output or changing the catalog', function (): void {
    Process::fake(fn () => Process::result('olderVideo1', 'private extraction diagnostics', 1));

    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertFailed();

    $this->assertDatabaseCount('channels', 0);
    $this->get('/watch-later')->assertInertia(fn (Assert $page) => $page
        ->component('watch-later')
        ->where('syncError', 'Не удалось прочитать «Смотреть позже». Проверьте сессию YouTube и доступность yt-dlp.')
        ->has('videos.data', 0));
});

it('rechecks videos skipped by duration only after the Watch Later cache expires', function (): void {
    config([
        'app.videos.min_video_duration' => 120,
        'app.videos.max_video_duration' => 3600,
    ]);
    Process::fake(fn () => Process::result("shortVideo1\nlongVideo01\n"));

    $resources = Mockery::mock(VideosResource::class);
    $resources->shouldReceive('listVideos')
        ->twice()
        ->with('snippet,contentDetails,statistics,liveStreamingDetails', ['id' => 'shortVideo1,longVideo01'])
        ->andReturn(new VideoListResponse(['items' => [
            [
                'id' => 'shortVideo1',
                'snippet' => ['title' => 'Short video', 'liveBroadcastContent' => 'none'],
                'contentDetails' => ['duration' => 'PT1M'],
            ],
            [
                'id' => 'longVideo01',
                'snippet' => ['title' => 'Long video', 'liveBroadcastContent' => 'none'],
                'contentDetails' => ['duration' => 'PT2H'],
            ],
        ]]));

    $youTube = Mockery::mock(YouTube::class);
    $youTube->videos = $resources;
    app()->instance(YouTube::class, $youTube);

    $this->freezeTime();
    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertSuccessful();
    $this->travel(5)->minutes();
    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertSuccessful();

    $source = Channel::where('external_id', Channel::WATCH_LATER_ID)->sole();
    expect($source->last_synced_at->timestamp)->toBe(now()->timestamp);
    $resources->shouldHaveReceived('listVideos')->once();

    $this->travel(24)->hours();
    $this->artisan('youtube:parse-videos', ['--watch-later' => true])->assertSuccessful();
    $this->assertDatabaseCount('videos', 0);
});
