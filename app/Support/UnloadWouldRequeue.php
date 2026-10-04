<?php

namespace App\Support;

use RuntimeException;

/**
 * Файл видео не убрать: один из его источников качает всё сам, и воркер тут же
 * скачал бы видео снова. Сначала источник переключают на «по запросу».
 */
class UnloadWouldRequeue extends RuntimeException {}
