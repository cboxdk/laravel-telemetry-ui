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

    /**
     * A money amount in an ISO 4217 currency: `$12.34`, `€0.0042`, `1.2K DKK`.
     * Cents from a cent up; a single model call costs a fraction of one, so
     * below that up to four decimals instead of a misleading `$0.00`.
     */
    public static function money(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper(trim($currency));
        $currency = $currency !== '' ? $currency : 'USD';
        $abs = abs($amount);

        // Below a hundredth of a cent there is nothing useful left to print.
        if ($abs > 0 && $abs < 0.0001) {
            return '<'.self::money($amount < 0 ? -0.0001 : 0.0001, $currency);
        }

        $number = match (true) {
            $abs >= 1_000 => self::count($amount),
            $abs >= 0.01 || $abs == 0.0 => number_format($amount, 2, '.', ','),
            default => self::trimZeros(number_format($amount, 4, '.', ','), 2),
        };

        $symbol = match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => null,
        };

        if ($symbol === null) {
            return $number.' '.$currency;
        }

        return str_starts_with($number, '-') ? '-'.$symbol.substr($number, 1) : $symbol.$number;
    }

    /** Strip trailing zeros, keeping at least `$min` decimals. */
    private static function trimZeros(string $formatted, int $min): string
    {
        [$whole, $decimals] = array_pad(explode('.', $formatted, 2), 2, '');

        return $whole.'.'.str_pad(rtrim($decimals, '0'), $min, '0');
    }

    private static function trim(float $value): string
    {
        $formatted = number_format($value, abs($value) >= 100 ? 0 : (abs($value) >= 10 ? 1 : 2), '.', ',');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
