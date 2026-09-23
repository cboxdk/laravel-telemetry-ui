<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

/**
 * The dashboard's short list: routes with the most server errors, then the
 * slowest p95 — where to look first, without scanning the full routes table.
 *
 * @phpstan-import-type RouteRow from RoutesTable
 */
final class RoutesNeedingAttention extends RoutesTable
{
    protected ?string $drillPage = 'requests';

    public static function span(): int
    {
        return 2;
    }

    /**
     * @param  list<RouteRow>  $rows
     * @return list<RouteRow>
     */
    protected function rank(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b['5xx'], $b['p95'] ?? 0.0, $b['total']] <=> [$a['5xx'], $a['p95'] ?? 0.0, $a['total']]);

        return $rows;
    }

    protected function limit(): int
    {
        return 8;
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function tableTitle(): string
    {
        return 'Routes needing attention';
    }

    protected function tableSubtitle(): string
    {
        return 'Most server errors first, then the slowest p95 — click one for its story';
    }
}
