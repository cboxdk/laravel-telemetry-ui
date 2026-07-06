<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use DateTimeImmutable;

/**
 * Grafana-style time expressions for the `from`/`to` query params: plain
 * unix seconds, `now`, or `now-1h` / `now+30m` style offsets (units
 * s/m/h/d/w/M/y). Relative expressions are evaluated at view time, so a
 * shared `?from=now-1h&to=now` link always shows the last hour.
 */
final class TimeExpression
{
    private const UNIT_SECONDS = [
        's' => 1,
        'm' => 60,
        'h' => 3_600,
        'd' => 86_400,
        'w' => 7 * 86_400,
        'M' => 30 * 86_400,
        'y' => 365 * 86_400,
    ];

    public static function parse(string $value, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return new DateTimeImmutable('@'.$value);
        }

        $now ??= new DateTimeImmutable;

        if ($value === 'now') {
            return $now;
        }

        if (preg_match('/^now([+-])(\d+)([smhdwMy])$/', $value, $matches) !== 1) {
            return null;
        }

        $seconds = (int) $matches[2] * self::UNIT_SECONDS[$matches[3]];

        return $now->modify(($matches[1] === '-' ? '-' : '+').$seconds.' seconds');
    }

    /**
     * Human form for the header: relative expressions verbatim (they read
     * like Grafana), absolute timestamps as a short date.
     */
    public static function label(string $value): string
    {
        return ctype_digit($value = trim($value)) ? date('d/m H:i', (int) $value) : $value;
    }
}
