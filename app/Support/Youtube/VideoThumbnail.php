<?php

namespace App\Support\Youtube;

class VideoThumbnail
{
    /**
     * От большей к меньшей. maxres (1280×720) и standard (640×480) есть не у всех видео;
     * standard и high — 4:3 с чёрными полосами, клиенты обрезают их до 16:9.
     */
    private const array SIZES = ['maxres', 'standard', 'high', 'medium', 'default'];

    /**
     * Ссылки на все размеры из ответа API, от большей к меньшей.
     *
     * @return list<string>
     */
    public static function urls(mixed $snippet): array
    {
        $urls = array_map(fn (string $size) => $snippet?->thumbnails?->{$size}?->url, self::SIZES);

        return array_values(array_filter($urls));
    }
}
