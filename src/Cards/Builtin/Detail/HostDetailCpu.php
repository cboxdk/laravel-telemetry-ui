<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\SystemCharts;

final class HostDetailCpu extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        return [
            'title' => 'CPU load average',
            'query' => 'avg by (period) ('.$this->metric('', '__name__=~"system_cpu_load_average(_ratio)?"').')',
            'label' => 'period',
            'unit' => '',
            'type' => 'line',
        ];
    }
}
