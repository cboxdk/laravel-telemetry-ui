<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\SystemCharts;

final class HostDetailNetwork extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        return [
            'title' => 'Network I/O',
            'query' => $this->metric('system_network_io_bytes')->rate($this->rateWindow())->sumBy('direction'),
            'label' => 'direction',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
