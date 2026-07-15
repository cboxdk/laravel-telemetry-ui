<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Builtin\MetricFacetTable;

/**
 * Static-cache operations broken down by outcome (hit / miss / write /
 * invalidate / flush) — the list under the StaticCacheOverview chart.
 */
final class StaticCacheBreakdown extends MetricFacetTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Operations by outcome',
            'metric' => 'statamic_static_cache_operations_total',
            'keys' => ['operation' => 'Operation'],
            'valueColumn' => 'Count',
        ];
    }
}
