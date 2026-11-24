<?php

namespace App\Support\Youtube;

use Illuminate\Contracts\Process\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WatchLater
{
    public const string STATUS_KEY = 'youtube:watch-later:error';

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
