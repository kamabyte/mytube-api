<?php

namespace App\Support;

use App\Models\Video;

/**
 * Файл скачанного видео на медиадиске: videos/{channel_id}/{id}.{ext}.
 *
 * channel_id — основной источник на момент скачивания; потом он может
 * смениться (видео ушло из плейлиста, но осталось в канале), а файл не
 * переезжает. Поэтому сначала смотрим в каталог текущего источника,
 * а не нашли — в любой: id видео уникален.
 *
 * Путь собирается из конфига, а не через Storage::disk('media'): локальный
 * диск при создании пытается завести корень и падает, если медиадиск не
 * смонтирован, — а страница просмотра должна открываться и тогда.
 */
class VideoFile
{
    public static function path(Video $video): ?string
    {
        return self::paths($video)[0] ?? null;
    }

    /**
     * Все файлы видео (само видео и, например, субтитры рядом), абсолютные пути.
     *
     * @return list<string>
     */
    public static function paths(Video $video): array
    {
        $root = rtrim((string) config('filesystems.disks.media.root'), '/');

        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $matches = glob("$root/videos/$video->channel_id/$video->id.*") ?: [];

        if ($matches === []) {
            $matches = glob("$root/videos/*/$video->id.*") ?: [];
        }

        return array_values($matches);
    }
}
