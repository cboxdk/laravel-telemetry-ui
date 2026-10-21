<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\SystemCharts;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\ScopeLabels;

final class HostDetailMemory extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_memory_usage_bytes'));

        return [
            'title' => 'Memory',
            'query' => MetricQuery::raw('sum by (system_memory_state) (avg by ('.ScopeLabels::metrics('host').', system_memory_state) ('.$selector.'))'),
            'label' => 'system_memory_state',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
