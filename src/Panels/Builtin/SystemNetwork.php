<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

final class SystemNetwork extends SystemCharts
{
    protected function spec(): array
    {
        return [
            'title' => 'Network I/O',
            // `_total`: the instrument declares itself a monotonic
            // counter, which is what lets rate() read a reboot as a reset
            // rather than a cliff.
            'query' => $this->metric('system_network_io_bytes_total')->rate($this->rateWindow())->sumBy('network_io_direction'),
            'label' => 'network_io_direction',
            'unit' => 'bytes',
            'subtitle' => 'Bytes per second received and transmitted, all interfaces',
            'stats' => ['receive', 'transmit'],
            'rate' => true,
            'type' => 'area',
        ];
    }
}
