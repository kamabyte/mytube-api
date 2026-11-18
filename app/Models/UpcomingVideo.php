<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Премьера или трансляция, ещё не вышедшая в эфир. youtube:parse-videos
 * перепроверяет её после check_after и, когда эфир закончился, сохраняет как обычное видео.
 */
class UpcomingVideo extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'check_after' => 'datetime',
    ];

    /**
     * Пора перепроверить.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('check_after', '<=', now());
    }
}
