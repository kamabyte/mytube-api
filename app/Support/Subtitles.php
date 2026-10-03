<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Субтитры, вшитые в MP4. Воркер кладёт их дорожками mov_text: Apple TV их
 * читает, а браузеры (кроме Safari) — нет. Для веба дорожка извлекается в
 * WebVTT через ffmpeg и складывается в storage/app/subtitles.
 *
 * И список дорожек, и VTT привязаны к пути, размеру и времени изменения
 * файла: перекачанное видео получит их заново.
 */
class Subtitles
{
    /** Текстовые форматы, которые ffmpeg умеет перегнать в WebVTT. */
    private const array TEXT_CODECS = ['mov_text', 'subrip', 'webvtt', 'ass', 'ssa', 'text'];

    /**
     * @return list<array{track: int, language: string|null, label: string}>
     */
    public function tracks(string $path): array
    {
        $key = 'subtitles:tracks:'.$this->version($path);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = Process::timeout(30)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', 's',
            '-show_entries', 'stream=codec_name:stream_tags=language,title',
            '-of', 'json',
            $path,
        ]);

        if ($result->failed()) {
            // Без ffprobe или с битым файлом — просто без субтитров, и не кешируем.
            return [];
        }

        $streams = json_decode($result->output(), true)['streams'] ?? [];
        $tracks = [];
        $labels = [];

        foreach ($streams as $track => $stream) {
            if (! in_array($stream['codec_name'] ?? null, self::TEXT_CODECS, true)) {
                continue;
            }

            $language = $stream['tags']['language'] ?? null;
            $language = $language === 'und' ? null : $language;
            $label = $stream['tags']['title'] ?? $this->languageName($language) ?? 'Субтитры '.($track + 1);

            $labels[$label] = ($labels[$label] ?? 0) + 1;
            if ($labels[$label] > 1) {
                $label .= " ({$labels[$label]})";
            }

            $tracks[] = ['track' => $track, 'language' => $language, 'label' => $label];
        }

        Cache::forever($key, $tracks);

        return $tracks;
    }

    /**
     * Путь к VTT-файлу дорожки (номер среди субтитров файла, как в -map 0:s:N).
     */
    public function vtt(string $path, int $track): ?string
    {
        $directory = storage_path('app/subtitles');
        $target = "$directory/{$this->version($path)}-$track.vtt";

        if (File::exists($target)) {
            return $target;
        }

        File::ensureDirectoryExists($directory);
        $temp = "$target.".Str::random(8).'.tmp';

        $result = Process::timeout(120)->run([
            'ffmpeg', '-v', 'error', '-nostdin', '-y',
            '-i', $path,
            '-map', "0:s:$track",
            '-f', 'webvtt',
            $temp,
        ]);

        if ($result->failed() || ! File::exists($temp)) {
            File::delete($temp);

            return null;
        }

        File::move($temp, $target);

        return $target;
    }

    /** Отпечаток файла: меняется, если видео перекачали. */
    public function version(string $path): string
    {
        return substr(md5($path.'|'.filesize($path).'|'.filemtime($path)), 0, 16);
    }

    private function languageName(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $name = \Locale::getDisplayLanguage($language, 'ru');

        return $name === '' || $name === $language ? $language : Str::ucfirst($name);
    }
}
