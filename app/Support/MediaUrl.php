<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Ссылки на файлы из storage/app/public для веб-клиента.
 *
 * Модели отдают абсолютный адрес от APP_URL (http://<сервер>:8000/...) —
 * это нужно ТВ-клиентам. Браузер же может прийти по любому имени
 * (домен, IP, localhost), поэтому веб получает путь
 * от корня текущего хоста.
 */
class MediaUrl
{
    public static function public(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::isUrl($path)) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
