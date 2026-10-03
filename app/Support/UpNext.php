<?php

namespace App\Support;

use App\Models\Video;
use Illuminate\Support\Collection;

/**
 * Очередь «Далее» — общая для веба и ТВ-клиентов: следующие по списку канала
 * (от новых к старым после текущего), а когда канал закончился — свежее
 * с других каналов. Текущее видео и дубликаты не попадают.
 */
class UpNext
{
    public const int DEFAULT_LIMIT = 20;

    private const string CHANNEL_COLUMNS = 'channel:id,name,thumbnail,is_playlist';

    /**
     * @param  list<string>  $columns
     * @return Collection<int, Video>
     */
    public static function for(Video $video, int $limit = self::DEFAULT_LIMIT, array $columns = ['*']): Collection
    {
        if ($limit < 1) {
            return new Collection;
        }

        $older = Video::query()
            ->select($columns)
            ->with(self::CHANNEL_COLUMNS)
            ->where('channel_id', $video->channel_id)
            ->whereKeyNot($video->id)
            // «Старше» — то, что идёт следом при сортировке published_at desc, id desc.
            // В SQLite NULL меньше любой даты, поэтому видео без даты публикации —
            // в самом конце: для датированного они все «старше», для недатированного —
            // только недатированные с меньшим id.
            ->where(fn ($query) => $video->published_at === null
                ? $query->whereNull('published_at')->where('id', '<', $video->id)
                : $query
                    ->where('published_at', '<', $video->published_at)
                    ->orWhere(fn ($query) => $query
                        ->where('published_at', $video->published_at)
                        ->where('id', '<', $video->id))
                    ->orWhereNull('published_at'))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($older->count() >= $limit) {
            return $older;
        }

        $others = Video::query()
            ->select($columns)
            ->with(self::CHANNEL_COLUMNS)
            ->where('channel_id', '!=', $video->channel_id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit - $older->count())
            ->get();

        return $older->concat($others);
    }
}
