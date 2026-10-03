<?php

namespace App\Support\Youtube;

use App\Models\Channel;
use App\Support\StoresPublicThumbnail;
use Carbon\CarbonInterface;
use Closure;
use Google\Service\YouTube;
use Illuminate\Support\Str;

/**
 * Добавление канала или плейлиста по адресу с YouTube: название, аватар,
 * uploads-плейлист. Единственное место, которое ходит за этим в YouTube API, —
 * им пользуются и консольные команды, и веб-клиент.
 */
class ChannelImporter
{
    public function __construct(
        private readonly YouTube $youTube,
        private readonly StoresPublicThumbnail $thumbnailStore,
    ) {}

    /**
     * Плейлист — это адрес с ?list= или голый id плейлиста (PL…, OL…).
     */
    public static function looksLikePlaylist(string $url): bool
    {
        $url = trim($url);

        if (Str::contains($url, ['/', '?', '&'])) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return filled($query['list'] ?? null);
        }

        return (bool) preg_match('/^(PL|OL|FL)[\w-]{10,}$/', $url);
    }

    /**
     * @throws ImportFailed
     */
    public function importChannel(
        string $channelUrl,
        bool $parseLatest = true,
        bool $parsePopular = false,
        ?CarbonInterface $syncFrom = null,
        ?string $playlistId = null,
    ): Channel {
        $part = $this->channelIdentifier($channelUrl);

        if ($part === null) {
            throw new ImportFailed('Could not read a channel out of the given url.', ImportFailed::BAD_URL);
        }

        $method = str_starts_with($part, 'UC') ? 'id' : 'forHandle';

        $item = $this->youTube->channels->listChannels('snippet,contentDetails', [
            $method => $part,
        ])->getItems()[0] ?? null;

        if (! $item) {
            throw new ImportFailed('Channel was not found.', ImportFailed::NOT_FOUND);
        }

        $username = ltrim($item->snippet->customUrl ?? $part, '@');

        $thumbnailUrl = $item->snippet->thumbnails->high->url
            ?? $item->snippet->thumbnails->medium->url
            ?? $item->snippet->thumbnails->default->url
            ?? null;

        $existingChannel = Channel::query()->where('external_id', $item->id)->first();

        $attributes = [
            'username' => $username ?: null,
            'name' => $item->getSnippet()->getTitle(),
            'thumbnail' => $this->thumbnailStore->replaceFromUrl(
                $thumbnailUrl,
                $existingChannel?->getRawOriginal('thumbnail'),
                'thumbnails/channels',
                $item->id,
            ),
            'uploads_playlist_id' => $playlistId ?? $item->contentDetails->relatedPlaylists->uploads ?? null,
            'parse_latest' => $parseLatest,
            'parse_popular' => $parsePopular,
        ];

        if ($syncFrom !== null) {
            $attributes['last_synced_at'] = $syncFrom;
        }

        return Channel::updateOrCreate(['external_id' => $item->id], $attributes);
    }

    /**
     * @param  Closure(string): void|null  $warn  Предупреждения, которые не мешают добавлению.
     *
     * @throws ImportFailed
     */
    public function importPlaylist(
        string $playlistUrl,
        ?string $name = null,
        ?string $thumbnail = null,
        ?string $sourceChannel = null,
        bool $parseLatest = true,
        bool $parsePopular = false,
        ?Closure $warn = null,
    ): Channel {
        $warn ??= fn (string $message) => null;
        $playlistId = $this->playlistIdentifier($playlistUrl);

        if ($playlistId === null) {
            throw new ImportFailed('Could not read a playlist id out of the given url.', ImportFailed::BAD_URL);
        }

        $playlist = $this->youTube->playlists->listPlaylists('snippet', ['id' => $playlistId])
            ->getItems()[0] ?? null;

        if (! $playlist) {
            throw new ImportFailed("Playlist {$playlistId} was not found. Private playlists are invisible to an api key — make it unlisted.", ImportFailed::NOT_FOUND);
        }

        $sourceChannelId = $sourceChannel
            ? Str::of($sourceChannel)->explode('/')->last()
            : $this->playlistOwnerId($playlistId);
        $source = $sourceChannelId ? $this->fetchChannel($sourceChannelId) : null;

        if ($sourceChannelId !== null && ! $source) {
            $warn("Source channel {$sourceChannelId} was not found, the playlist artwork is used instead.");
        }

        $existingPlaylist = Channel::query()->where('external_id', $playlistId)->first();

        return Channel::updateOrCreate(['external_id' => $playlistId], [
            'name' => $name ?: $playlist->getSnippet()->getTitle(),
            'thumbnail' => $this->thumbnailStore->replaceFromUrl(
                $thumbnail ?: $this->pickThumbnailUrl($source?->snippet ?? $playlist->getSnippet()),
                $existingPlaylist?->getRawOriginal('thumbnail'),
                'thumbnails/channels',
                $playlistId,
            ),
            'uploads_playlist_id' => $playlistId,
            'is_playlist' => true,
            'parse_latest' => $parseLatest,
            'parse_popular' => $parsePopular,
        ]);
    }

    /**
     * «UC…», «@handle», «handle» или адрес канала в любом виде:
     * /channel/UC…, /@handle, /@handle/videos, с параметрами и без.
     */
    private function channelIdentifier(string $channelUrl): ?string
    {
        $channelUrl = trim($channelUrl);

        if (preg_match('~/channel/(UC[\w-]+)~', $channelUrl, $matches)) {
            return $matches[1];
        }

        if (preg_match('~(?:^|/)(@[\w.\-]+)~u', $channelUrl, $matches)) {
            return $matches[1];
        }

        $part = (string) Str::of($channelUrl)->before('?')->rtrim('/')->explode('/')->last();

        return $part !== '' ? $part : null;
    }

    /**
     * Полный адрес («…/playlist?list=PL…», «…/watch?v=…&list=PL…») или голый id.
     */
    private function playlistIdentifier(string $playlistUrl): ?string
    {
        $playlistUrl = trim($playlistUrl);

        if (! Str::contains($playlistUrl, ['/', '?', '&'])) {
            return $playlistUrl ?: null;
        }

        parse_str((string) parse_url($playlistUrl, PHP_URL_QUERY), $query);

        return isset($query['list']) && $query['list'] !== '' ? (string) $query['list'] : null;
    }

    /**
     * Владелец первого видео — то, что нужно плейлисту «избранное одного канала».
     */
    private function playlistOwnerId(string $playlistId): ?string
    {
        $item = $this->youTube->playlistItems->listPlaylistItems('snippet', [
            'playlistId' => $playlistId,
            'maxResults' => 1,
        ])->getItems()[0] ?? null;

        return $item?->getSnippet()?->getVideoOwnerChannelId();
    }

    private function fetchChannel(string $channelId): ?YouTube\Channel
    {
        $method = str_starts_with($channelId, 'UC') ? 'id' : 'forHandle';

        return $this->youTube->channels->listChannels('snippet', [
            $method => ltrim($channelId, '@'),
        ])->getItems()[0] ?? null;
    }

    private function pickThumbnailUrl(mixed $snippet): ?string
    {
        return $snippet?->thumbnails?->high?->url
            ?? $snippet?->thumbnails?->medium?->url
            ?? $snippet?->thumbnails?->default?->url
            ?? null;
    }
}
