<?php

namespace App\Models;

use App\Models\Scopes\DownloadedVideo;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    /** Строка running старше этого — воркер упал на полпути, а не качает. */
    private const int STALE_RUN_HOURS = 6;

    protected $guarded = ['id'];

    protected $casts = [
        'is_downloaded' => 'boolean',
        'is_unavailable' => 'boolean',
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'view_count' => 'integer',
        'published_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'download_requested_at' => 'datetime',
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

    /**
     * Каталог: всё, что завёл парсер, скачанное или нет, кроме удалённого вручную.
     *
     * @return Builder<static>
     */
    public static function catalog(): Builder
    {
        return static::withoutGlobalScope(DownloadedVideo::class)->whereNull('videos.removed_at');
    }

    /**
     * Ждёт воркера. То же условие, по которому воркер выбирает задания
     * (mytube-workers, DbJobSource): попросили или хоть один источник
     * видео качает всё сам.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingDownload(Builder $query): void
    {
        $query->where('videos.is_downloaded', false)
            ->where('videos.is_unavailable', false)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('videos.download_requested_at')
                ->orWhereHas('channels', fn (Builder $query) => $query->where('download_on_demand', false)));
    }

    /**
     * Подгружает то, без чего DownloadState не отличит очередь от каталога
     * и загрузку от ожидания.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithDownloadState(Builder $query): void
    {
        $query->withExists([
            'channels as auto_download' => fn (Builder $query) => $query->where('download_on_demand', false),
            'downloadRuns as downloading' => fn (Builder $query) => $query
                ->where('status', VideoDownloadRun::RUNNING)
                ->where('started_at', '>=', now()->subHours(self::STALE_RUN_HOURS)),
        ]);
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

    public function downloadRuns(): HasMany
    {
        return $this->hasMany(VideoDownloadRun::class);
    }
}
