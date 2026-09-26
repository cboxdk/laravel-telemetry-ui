<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

final class SystemFilesystem extends SystemCharts
{
    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_filesystem_usage_bytes'));

        return [
            'title' => 'Filesystem',
            'query' => MetricQuery::raw('sum by (system_filesystem_state) (avg by (host_name, system_filesystem_state) ('.$selector.'))'),
            'label' => 'system_filesystem_state',
            'unit' => 'bytes',
            'subtitle' => 'Disk space used and free across mounted filesystems',
            'stats' => ['used', 'free'],
            'type' => 'area',
        ];
    }
}
