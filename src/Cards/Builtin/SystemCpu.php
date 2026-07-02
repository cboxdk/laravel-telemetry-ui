<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

final class SystemCpu extends SystemCharts
{
    protected function spec(): array
    {
        return [
            'title' => 'CPU load average',
            'query' => 'avg by (period) ('.$this->metric('system_cpu_load_average').')',
            'label' => 'period',
            'unit' => '',
            'type' => 'line',
        ];
    }
}
