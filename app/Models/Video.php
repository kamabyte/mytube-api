<?php

namespace App\Models;

use App\Models\Scopes\DownloadedVideo;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_downloaded' => 'boolean',
        'is_unavailable' => 'boolean',
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'view_count' => 'integer',
        'published_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'removed_at' => 'datetime',
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

    protected static function booted(): void
    {
        static::addGlobalScope(new DownloadedVideo);

        // Основной источник видео — всегда и один из его источников.
        static::created(function (Video $video): void {
            $video->channels()->syncWithoutDetaching([$video->channel_id]);
        });
    }

    // relations

    /**
     * Основной источник: канал автора, если он отслеживается, иначе плейлист.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Все каналы и плейлисты, в которых лежит видео.
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }
}
