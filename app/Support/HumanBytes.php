<?php

namespace App\Support;

/**
 * Размер по-русски для сообщений веб-клиента: «1,9 МБ», «12 ГБ».
 */
class HumanBytes
{
    private const array UNITS = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];

    public static function format(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 Б';
        }

        $exponent = min((int) floor(log($bytes, 1024)), count(self::UNITS) - 1);
        $value = $bytes / 1024 ** $exponent;

        $decimals = $value >= 100 || $exponent === 0 || round($value, 1) == round($value) ? 0 : 1;

        return number_format($value, $decimals, ',', ' ').' '.self::UNITS[$exponent];
    }
}
