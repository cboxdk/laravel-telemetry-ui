<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\SystemCharts;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

final class HostDetailMemory extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_memory_usage_bytes'));

        return [
            'title' => 'Memory',
            'query' => MetricQuery::raw('sum by (state) (avg by (host_name, state) ('.$selector.'))'),
            'label' => 'state',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
