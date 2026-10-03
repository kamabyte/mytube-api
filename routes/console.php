<?php

use App\Console\Commands\ParseYoutubeVideos;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ParseYoutubeVideos::class)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/schedule-youtube-parse-recent-videos.log'));
