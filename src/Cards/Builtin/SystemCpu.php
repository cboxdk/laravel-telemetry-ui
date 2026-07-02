<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

final class SystemCpu extends SystemCharts
{
    protected function spec(): array
    {
        // OTLP ingestion suffixes unit-"1" gauges with _ratio; a direct
        // Prometheus scrape of the package endpoint does not.
        return [
            'title' => 'CPU load average',
            'query' => 'avg by (period) ('.$this->metric('', '__name__=~"system_cpu_load_average(_ratio)?"').')',
            'label' => 'period',
            'unit' => '',
            'type' => 'line',
        ];
    }
}
