<?php

namespace App\Support;

use RuntimeException;

/**
 * Видео не скачать: YouTube его не отдаёт (удалено, закрыто, недоступно в регионе).
 */
class VideoUnavailable extends RuntimeException {}
