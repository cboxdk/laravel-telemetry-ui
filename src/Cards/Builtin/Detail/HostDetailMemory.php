<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\SystemCharts;

final class HostDetailMemory extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        return [
            'title' => 'Memory',
            'query' => 'sum by (state) (avg by (host_name, state) ('.$this->metric('system_memory_usage_bytes').'))',
            'label' => 'state',
            'unit' => 'bytes',
            'type' => 'area',
        ];
    }
}
