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
        'download_on_demand' => 'boolean',
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
     * (mytube-workers, DbJobSource): ручной запрос или разрешённое автоскачивание.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingDownload(Builder $query): void
    {
        $query->where('videos.is_downloaded', false)
            ->where('videos.is_unavailable', false)
            ->whereNull('videos.removed_at')
            ->where(fn (Builder $query) => $query
                ->whereNotNull('videos.download_requested_at')
                ->orWhere(fn (Builder $query) => $query->automaticallyDownloadable()));
    }

    public function scopeAutomaticallyDownloadable(Builder $query): void
    {
        $query->whereHas('channels', $this->filterAutomaticDownloadSources(...));
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
            'channels as auto_download' => $this->filterAutomaticDownloadSources(...),
            'downloadRuns as downloading' => fn (Builder $query) => $query->running(),
        ]);
    }

    private function filterAutomaticDownloadSources(Builder $query): void
    {
        // true — только ручной запрос, false — автоскачивание из активного источника,
        // null — наследуем режим каждого канала или плейлиста.
        $query->where('channels.sync_paused', false)
            ->where(fn (Builder $query) => $query
                ->where('videos.download_on_demand', false)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('videos.download_on_demand')
                    ->where('channels.download_on_demand', false)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('channels.auto_download_from')
                        ->orWhereColumn('videos.published_at', '>=', 'channels.auto_download_from'))));
    }

    /**
     * Порядок, в котором воркер берёт задания (mytube-workers, DbJobSource):
     * сначала запрошенные — по времени запроса, потом очередь каналов
     * с автоскачиванием — от старых видео к новым.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInDownloadOrder(Builder $query): void
    {
        $query->orderByRaw('videos.download_requested_at IS NULL')
            ->orderBy('videos.download_requested_at')
            ->orderBy('videos.published_at')
            ->orderBy('videos.id');
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
