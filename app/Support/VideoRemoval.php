<?php

namespace App\Support;

/**
 * Итог удаления или выгрузки видео.
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
