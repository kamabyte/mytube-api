<?php

namespace App\Support\Youtube;

use Illuminate\Contracts\Process\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WatchLater
{
    public const string STATUS_KEY = 'youtube:watch-later:error';

    /** @param list<string> $ids */
    public function rememberDurationSkips(array $ids): void
    {
        if ($ids !== []) {
            $keys = array_map($this->durationCacheKey(...), $ids);
            Cache::putMany(array_fill_keys($keys, true), now()->addDay());
        }
    }

    /**
     * @param  array<int, string>  $ids
     * @return list<string>
     */
    public function withoutDurationSkips(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $keys = array_map($this->durationCacheKey(...), $ids);
        $idsByKey = array_combine($keys, $ids);
        $skips = array_filter(Cache::many($keys));

        return array_values(array_diff_key($idsByKey, $skips));
    }

    private function durationCacheKey(string $id): string
    {
        $min = config('app.videos.min_video_duration');
        $max = config('app.videos.max_video_duration');

        return "youtube:watch-later:duration-skip:{$min}:{$max}:{$id}";
    }

    /** @return list<string> */
    public function videoIds(): array
    {
        $source = config('services.youtube.watch_later_cookies');

        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Файл сессии YouTube недоступен.');
        }

        // yt-dlp может перезаписать cookies. Исходный файл остаётся у владельца.
        $cookies = tempnam(sys_get_temp_dir(), 'mytube-watch-later-');

        if ($cookies === false) {
            throw new RuntimeException('Не удалось подготовить файл сессии YouTube.');
        }

        try {
            if (! @copy($source, $cookies)) {
                throw new RuntimeException('Не удалось прочитать файл сессии YouTube.');
            }

            $result = Process::timeout(90)->run([
                'yt-dlp',
                '--ignore-config',
                '--no-cache-dir',
                '--no-ignore-errors',
                '--flat-playlist',
                '--skip-download',
                '--print', '%(id)s',
                '--cookies', $cookies,
                ':ytwatchlater',
            ]);

            if ($result->failed()) {
                throw new RuntimeException('Не удалось прочитать «Смотреть позже». Проверьте сессию YouTube и доступность yt-dlp.');
            }

            $ids = preg_split('/\R/', trim($result->output()), -1, PREG_SPLIT_NO_EMPTY);

            return array_values(array_unique(array_filter($ids, fn (string $id) => preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id))));
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('YouTube не ответил вовремя.');
        } finally {
            unlink($cookies);
        }
    }
}
