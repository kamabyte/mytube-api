<?php

namespace App\Models;

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
