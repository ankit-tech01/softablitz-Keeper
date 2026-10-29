<?php

namespace App\Support;

class ByteSizeParser
{
    public static function parse(string $value): ?int
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)?$/iD', trim($value), $matches)) {
            return null;
        }

        $unit = strtoupper($matches[2] ?? 'B');
        $multiplier = match ($unit) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        };
        $bytes = (float) $matches[1] * $multiplier;

        if (! is_finite($bytes) || $bytes >= PHP_INT_MAX) {
            return null;
        }

        return (int) round($bytes);
    }
}
