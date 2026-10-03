<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoresPublicThumbnail
{
    public function storeFromUrl(?string $url, string $directory, string $filename): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $response = Http::timeout(20)
            ->retry(2, 250)
            ->withHeaders([
                'User-Agent' => 'mytube-thumbnail-fetcher/1.0',
            ])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $extension = $this->resolveExtension($url, $response->header('Content-Type'));
        $path = trim($directory, '/').'/'.$filename.'.'.$extension;

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    public function replaceFromUrl(?string $url, ?string $currentPath, string $directory, string $filename): ?string
    {
        $path = $this->storeFromUrl($url, $directory, $filename);

        if (! $path) {
            return $currentPath;
        }

        if ($currentPath && $currentPath !== $path && ! Str::isUrl($currentPath)) {
            Storage::disk('public')->delete($currentPath);
        }

        return $path;
    }

    private function resolveExtension(string $url, ?string $contentType): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return match (strtolower(strtok($contentType ?? '', ';'))) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
