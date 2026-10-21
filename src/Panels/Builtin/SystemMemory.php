<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\ScopeLabels;

final class SystemMemory extends SystemCharts
{
    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_memory_usage_bytes'));

        return [
            'title' => 'Memory',
            'query' => MetricQuery::raw('sum by (system_memory_state) (avg by ('.ScopeLabels::metrics('host').', system_memory_state) ('.$selector.'))'),
            'label' => 'system_memory_state',
            'unit' => 'bytes',
            'type' => 'area',
            'subtitle' => 'Memory by state across hosts — used vs. what the OS can reclaim (cached, buffers, free)',
            'stats' => ['used', 'free', 'cached'],
        ];
    }
}
