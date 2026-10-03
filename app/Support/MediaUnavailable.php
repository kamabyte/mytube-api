<?php

namespace App\Support;

use RuntimeException;

/**
 * Медиадиск не смонтирован: удалять записи нельзя, иначе файлы останутся
 * лежать сиротами.
 */
class MediaUnavailable extends RuntimeException {}
