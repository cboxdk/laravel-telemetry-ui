<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

final class SystemMemory extends SystemCharts
{
    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_memory_usage_bytes'));

        return [
            'title' => 'Memory',
            'query' => MetricQuery::raw('sum by (state) (avg by (host_name, state) ('.$selector.'))'),
            'label' => 'state',
            'unit' => 'bytes',
            'type' => 'area',
            'subtitle' => 'Memory by state across hosts — used vs. what the OS can reclaim (cached, buffers, free)',
            'stats' => ['used', 'free', 'cached'],
        ];
    }
}
