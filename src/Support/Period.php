<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use DateTimeImmutable;

/**
 * The global dashboard time window (Nightwatch-style 1H/24H/7D/14D/30D).
 */
enum Period: string
{
    case FifteenMinutes = '15m';
    case OneHour = '1h';
    case OneDay = '24h';
    case SevenDays = '7d';
    case FourteenDays = '14d';
    case ThirtyDays = '30d';

    public static function default(): self
    {
        return self::OneHour;
    }

    public function seconds(): int
    {
        return match ($this) {
            self::FifteenMinutes => 15 * 60,
            self::OneHour => 60 * 60,
            self::OneDay => 24 * 60 * 60,
            self::SevenDays => 7 * 24 * 60 * 60,
            self::FourteenDays => 14 * 24 * 60 * 60,
            self::ThirtyDays => 30 * 24 * 60 * 60,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FifteenMinutes => '15M',
            self::OneHour => '1H',
            self::OneDay => '24H',
            self::SevenDays => '7D',
            self::FourteenDays => '14D',
            self::ThirtyDays => '30D',
        };
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function range(): array
    {
        $end = new DateTimeImmutable;

        return [$end->modify('-'.$this->seconds().' seconds'), $end];
    }

    /**
     * The rate window for an arbitrary range length (custom from/to ranges).
     */
    public static function windowFor(int $seconds): string
    {
        return match (true) {
            $seconds <= 900 => '1m',
            $seconds <= 3_600 => '5m',
            $seconds <= 86_400 => '15m',
            $seconds <= 7 * 86_400 => '1h',
            $seconds <= 14 * 86_400 => '2h',
            default => '4h',
        };
    }

    /**
     * The whole period as a PromQL duration, for period-total increase().
     */
    public function promDuration(): string
    {
        return $this->seconds().'s';
    }
}
