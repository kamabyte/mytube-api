<?php

use App\Console\Commands\ParseYoutubeVideos;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ParseYoutubeVideos::class)
    // Раз в минуту: каналы проверяются по RSS (без квоты API), плейлисты —
    // не чаще раза в 10 минут (ParseYoutubeVideos::PLAYLIST_INTERVAL_MINUTES).
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/schedule-youtube-parse-recent-videos.log'));

Schedule::command(ParseYoutubeVideos::class, ['--watch-later'])
    ->when(fn () => config('services.youtube.watch_later_enabled'))
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/schedule-youtube-watch-later.log'));
