<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Попытка скачивания. Строки пишет воркер (mytube-workers, DbRunRecorder):
 * открывает со статусом running и закрывает с итогом.
 */
class VideoDownloadRun extends Model
{
    public const string RUNNING = 'running';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
