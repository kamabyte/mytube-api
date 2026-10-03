<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/media');
    Config::set('filesystems.disks.media.root', $this->mediaRoot);
    Storage::forgetDisk('media');
    File::deleteDirectory(storage_path('app/subtitles'));

    $channel = Channel::query()->create([
        'external_id' => 'channel-subtitles',
        'username' => 'channel-subtitles',
        'name' => 'Channel',
    ]);

    $this->video = Video::query()->create([
        'external_id' => 'video-subtitles',
        'channel_id' => $channel->id,
        'is_downloaded' => true,
        'duration_seconds' => 60,
        'view_count' => 0,
        'name' => 'Subtitled',
        'published_at' => now(),
    ]);

    File::ensureDirectoryExists($this->mediaRoot."/videos/{$channel->id}");
    File::put($this->mediaRoot."/videos/{$channel->id}/{$this->video->id}.mp4", 'mp4');

    $this->ffprobe = json_encode(['streams' => [
        ['codec_name' => 'mov_text', 'tags' => ['language' => 'eng']],
        ['codec_name' => 'hdmv_pgs_subtitle', 'tags' => ['language' => 'deu']],
        ['codec_name' => 'mov_text', 'tags' => ['language' => 'rus']],
    ]]);
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/subtitles'));
});

it('lists text subtitle tracks on the watch page', function (): void {
    Process::fake(['*ffprobe*' => Process::result($this->ffprobe)]);

    $this->get("/watch/{$this->video->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('watch')
            ->has('video.subtitles', 2)
            ->where('video.subtitles.0.track', 0)
            ->where('video.subtitles.0.language', 'eng')
            ->where('video.subtitles.0.label', 'Английский')
            ->where('video.subtitles.1.track', 2)
            ->where('video.subtitles.1.label', 'Русский')
            ->where('video.subtitles.1.url', fn (string $url) => str_starts_with($url, "/api/videos/{$this->video->id}/subtitles/2?v=")));

    // Второй заход — из кеша, без ffprobe.
    $this->get("/watch/{$this->video->id}")->assertOk();
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'ffprobe', 1);
});

it('shows no subtitles when ffprobe fails', function (): void {
    Process::fake(['*ffprobe*' => Process::result(exitCode: 1)]);

    $this->get("/watch/{$this->video->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('video.subtitles', 0));
});

it('extracts a track to WebVTT once and serves it', function (): void {
    Process::fake([
        '*ffprobe*' => Process::result($this->ffprobe),
        '*ffmpeg*' => function (PendingProcess $process) {
            File::put(end($process->command), "WEBVTT\n\n00:01.000 --> 00:02.000\nПривет\n");

            return Process::result();
        },
    ]);

    $response = $this->get("/api/videos/{$this->video->id}/subtitles/2");
    $response->assertOk();
    $response->assertHeader('content-type', 'text/vtt; charset=utf-8');
    expect($response->baseResponse->getFile()->getContent())->toContain('Привет');

    $this->get("/api/videos/{$this->video->id}/subtitles/2")->assertOk();
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'ffmpeg', 1);
    Process::assertRan(fn (PendingProcess $process) => in_array('0:s:2', $process->command, true));
});

it('rejects tracks that are missing or not text', function (): void {
    Process::fake(['*ffprobe*' => Process::result($this->ffprobe)]);

    $this->get("/api/videos/{$this->video->id}/subtitles/1")->assertNotFound();
    $this->get("/api/videos/{$this->video->id}/subtitles/7")->assertNotFound();
    Process::assertDidntRun(fn (PendingProcess $process) => $process->command[0] === 'ffmpeg');
});
