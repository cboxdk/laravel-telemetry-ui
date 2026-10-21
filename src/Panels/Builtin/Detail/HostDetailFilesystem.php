<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\SystemCharts;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\ScopeLabels;

final class HostDetailFilesystem extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        $selector = (new PromqlCompiler)->compile($this->metric('system_filesystem_usage_bytes'));

        return [
            'title' => 'Filesystem',
            'query' => MetricQuery::raw('sum by (system_filesystem_state) (avg by ('.ScopeLabels::metrics('host').', system_filesystem_state) ('.$selector.'))'),
            'label' => 'system_filesystem_state',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
