<?php

namespace App\Models;

use Closure;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'parse_popular' => 'boolean',
        'parse_latest' => 'boolean',
        'is_playlist' => 'boolean',
        'download_on_demand' => 'boolean',
    ];

    public function getThumbnailAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (Str::isUrl($value)) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    /**
     * Видео каталога: скачанные и нет, без удалённых вручную. Для агрегатов по
     * отношению videos, которое без этого видит только скачанное (глобальный скоуп):
     * ->withMax(Channel::catalogVideos(), 'published_at') — «обновлён» по всему каталогу.
     *
     * @return array<string, Closure>
     */
    public static function catalogVideos(): array
    {
        return ['videos' => fn ($query) => $query->withoutGlobalScopes()->whereNull('videos.removed_at')];
    }

    /**
     * catalog_count — всё, что можно смотреть или скачать: каталог без недоступных.
     * Рядом с videos_count (скачанное) даёт пару «скачано / ещё не скачано».
     *
     * @return array<string, Closure>
     */
    public static function catalogCount(): array
    {
        return ['videos as catalog_count' => fn ($query) => $query
            ->withoutGlobalScopes()
            ->whereNull('videos.removed_at')
            ->where('videos.is_unavailable', false)];
    }

    // relations

    /**
     * Все видео канала или плейлиста — в том числе те, что принадлежат
     * другому источнику (videos.channel_id), но лежат и здесь.
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class);
    }

    public function recentVideos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class)
            ->latest('published_at')
            ->limit(15);
    }
}
