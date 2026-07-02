<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

final class SystemNetwork extends SystemCharts
{
    protected function spec(): array
    {
        return [
            'title' => 'Network I/O',
            'query' => 'sum by (direction) (rate('.$this->metric('system_network_io_bytes').'['.$this->rateWindow().']))',
            'label' => 'direction',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
