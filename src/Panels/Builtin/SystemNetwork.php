<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

final class SystemNetwork extends SystemCharts
{
    protected function spec(): array
    {
        return [
            'title' => 'Network I/O',
            'query' => $this->metric('system_network_io_bytes')->rate($this->rateWindow())->sumBy('direction'),
            'label' => 'direction',
            'unit' => 'bytes',
            'subtitle' => 'Bytes per second received and transmitted, all interfaces',
            'stats' => ['receive', 'transmit'],
            'rate' => true,
            'type' => 'area',
        ];
    }
}
