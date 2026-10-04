<?php

namespace App\Support\Youtube;

class VideoThumbnail
{
    /**
     * От большей к меньшей. maxres (1280×720) и standard (640×480) есть не у всех видео;
     * standard и high — 4:3 с чёрными полосами, клиенты обрезают их до 16:9.
     */
    private const array SIZES = ['maxres', 'standard', 'high', 'medium', 'default'];

    public static function bestUrl(mixed $snippet): ?string
    {
        foreach (self::SIZES as $size) {
            $url = $snippet?->thumbnails?->{$size}?->url;

            if ($url) {
                return $url;
            }
        }

        return null;
    }
}
