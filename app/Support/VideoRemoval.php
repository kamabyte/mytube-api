<?php

namespace App\Support;

/**
 * Итог LibraryCleaner::removeVideo.
 */
final readonly class VideoRemoval
{
    /**
     * @param  list<string>  $keptFiles  Абсолютные пути файлов, которые удалить не вышло.
     */
    public function __construct(
        public int $freedBytes,
        public array $keptFiles,
    ) {}

    public function hasLeftovers(): bool
    {
        return $this->keptFiles !== [];
    }
}
