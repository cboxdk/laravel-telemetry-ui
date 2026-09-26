<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\SystemCharts;

final class HostDetailCpu extends SystemCharts
{
    use ScopesToMachine;

    protected function spec(): array
    {
        return [
            'title' => 'CPU load average',
            'query' => $this->loadAverageQuery(),
            'label' => 'window',
            'unit' => '',
            'type' => 'line',
        ];
    }
}
