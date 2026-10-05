<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Попытка скачивания. Строки пишет воркер (mytube-workers, DbRunRecorder):
 * открывает со статусом running и закрывает с итогом.
 */
class VideoDownloadRun extends Model
{
    public const string RUNNING = 'running';

    /** Строка running старше этого — воркер упал на полпути, а не качает. */
    private const int STALE_RUN_HOURS = 6;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Идёт сейчас: открыта и не брошена упавшим воркером.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('status', self::RUNNING)
            ->where('started_at', '>=', now()->subHours(self::STALE_RUN_HOURS));
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
