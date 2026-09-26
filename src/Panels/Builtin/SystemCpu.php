<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

final class SystemCpu extends SystemCharts
{
    protected function spec(): array
    {
        return [
            'title' => 'CPU load average',
            'query' => $this->loadAverageQuery(),
            'label' => 'window',
            'unit' => '',
            'type' => 'line',
            'subtitle' => 'Runnable processes averaged over 1, 5 and 15 minutes — compare with the core count',
            'stats' => ['1m', '5m', '15m'],
        ];
    }
}
