<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

final class SystemCpu extends SystemCharts
{
    protected function spec(): array
    {
        // OTLP ingestion suffixes unit-"1" gauges with _ratio; a direct
        // Prometheus scrape of the package endpoint does not.
        return [
            'title' => 'CPU load average',
            'query' => $this->metric('', '__name__=~"system_cpu_load_average(_ratio)?"')->avgBy('period'),
            'label' => 'period',
            'unit' => '',
            'type' => 'line',
            'subtitle' => 'Runnable processes averaged over 1, 5 and 15 minutes — compare with the core count',
            'stats' => ['1m', '5m', '15m'],
        ];
    }
}
