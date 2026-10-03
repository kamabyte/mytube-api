<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Итог LibraryCleaner::removeChannel: записи удалены всегда, а здесь —
 * что из файлов удалить не вышло.
 */
final readonly class ChannelRemoval
{
    /**
     * @param  Collection<int, string>  $keptThumbnails  Пути на диске public.
     */
    public function __construct(
        public bool $keptMedia,
        public Collection $keptThumbnails,
    ) {}

    public function hasLeftovers(): bool
    {
        return $this->keptMedia || $this->keptThumbnails->isNotEmpty();
    }
}
