<?php

namespace App\Support\Youtube;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Свежие загрузки канала из его RSS (youtube.com/feeds/videos.xml) — без
 * YouTube Data API и без квоты. Лента отдаёт только последние FEED_SIZE видео,
 * поэтому она годится, чтобы заметить новое, а не чтобы пройти канал целиком.
 */
class UploadsFeed
{
    /** Сколько последних загрузок отдаёт лента. */
    public const int FEED_SIZE = 15;

    private const string URL = 'https://www.youtube.com/feeds/videos.xml';

    private const string ATOM = 'http://www.w3.org/2005/Atom';

    private const string YT = 'http://www.youtube.com/xml/schemas/2015';

    /**
     * Загрузки от новых к старым; null — лента недоступна или не разобралась.
     *
     * @return list<array{video_id: string, published_at: CarbonImmutable}>|null
     */
    public function latest(string $channelId): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::URL, ['channel_id' => $channelId]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        try {
            $feed = new SimpleXMLElement($response->body(), LIBXML_NONET);
        } catch (Throwable) {
            return null;
        }

        $entries = [];

        foreach ($feed->children(self::ATOM)->entry as $entry) {
            $videoId = (string) $entry->children(self::YT)->videoId;
            $publishedAt = (string) $entry->children(self::ATOM)->published;

            if ($videoId === '' || $publishedAt === '') {
                continue;
            }

            $entries[] = [
                'video_id' => $videoId,
                'published_at' => CarbonImmutable::parse($publishedAt),
            ];
        }

        return $entries;
    }

    /**
     * RSS есть только у загрузок канала: у плейлистов и у канала, которому
     * подменили плейлист загрузок (--playlist-id), ленту брать неоткуда.
     */
    public static function covers(string $channelId, ?string $uploadsPlaylistId): bool
    {
        return str_starts_with($channelId, 'UC')
            && $uploadsPlaylistId === 'UU'.substr($channelId, 2);
    }
}
