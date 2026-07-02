<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * Nightwatch-style compact number formatting for stats and tables.
 */
final class Format
{
    public static function count(float $value): string
    {
        // Counter increase() extrapolates, so near-integers are noise.
        $value = round($value);

        $abs = abs($value);

        return match (true) {
            $abs >= 1_000_000_000 => self::trim($value / 1_000_000_000).'B',
            $abs >= 1_000_000 => self::trim($value / 1_000_000).'M',
            $abs >= 1_000 => self::trim($value / 1_000).'K',
            default => self::trim($value),
        };
    }

    public static function ms(float $milliseconds): string
    {
        $abs = abs($milliseconds);

        return match (true) {
            $abs >= 3_600_000 => self::trim($milliseconds / 3_600_000).'h',
            $abs >= 60_000 => self::trim($milliseconds / 60_000).'min',
            $abs >= 1_000 => self::trim($milliseconds / 1_000).'s',
            $abs >= 1 => self::trim($milliseconds).'ms',
            $abs > 0 => self::trim($milliseconds * 1_000).'µs',
            default => '0ms',
        };
    }

    public static function bytes(float $bytes): string
    {
        $abs = abs($bytes);

        return match (true) {
            $abs >= 1_073_741_824 => self::trim($bytes / 1_073_741_824).' GB',
            $abs >= 1_048_576 => self::trim($bytes / 1_048_576).' MB',
            $abs >= 1_024 => self::trim($bytes / 1_024).' KB',
            default => self::trim($bytes).' B',
        };
    }

    public static function percent(float $ratio): string
    {
        return self::trim($ratio * 100).'%';
    }

    private static function trim(float $value): string
    {
        $formatted = number_format($value, abs($value) >= 100 ? 0 : (abs($value) >= 10 ? 1 : 2), '.', ',');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
