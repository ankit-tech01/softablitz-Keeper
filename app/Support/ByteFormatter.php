<?php

namespace App\Support;

class ByteFormatter
{
    public static function format(int|float $bytes): string
    {
        if ($bytes >= 1024 ** 3 || $bytes / (1024 ** 2) >= 1023.995) {
            return self::formatUnit($bytes / (1024 ** 3), 'GB');
        }

        if ($bytes >= 1024 ** 2) {
            return self::formatUnit($bytes / (1024 ** 2), 'MB');
        }

        return number_format($bytes).' B';
    }

    private static function formatUnit(float $value, string $unit): string
    {
        $formatted = number_format($value, 2, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.').' '.$unit;
    }
}
