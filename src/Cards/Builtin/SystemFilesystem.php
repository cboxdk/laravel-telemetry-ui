<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

final class SystemFilesystem extends SystemCharts
{
    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_filesystem_usage_bytes'));

        return [
            'title' => 'Filesystem',
            'query' => MetricQuery::raw('sum by (state) (avg by (host_name, state) ('.$selector.'))'),
            'label' => 'state',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
