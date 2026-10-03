<?php

namespace App\Support\Youtube;

use RuntimeException;

/**
 * Канал или плейлист не удалось добавить. Сообщение — для консоли,
 * reason — чтобы веб-клиент мог объяснить это по-русски.
 */
class ImportFailed extends RuntimeException
{
    public const string BAD_URL = 'bad_url';

    public const string NOT_FOUND = 'not_found';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
