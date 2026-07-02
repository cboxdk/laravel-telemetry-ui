<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

final class CommandsTable extends BreakdownTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Commands',
            'keyLabel' => 'command',
            'keyColumn' => 'Command',
            'durationMetric' => 'command_duration_milliseconds',
            'outcomes' => [
                'completed' => 'commands_completed_total',
                'failed' => 'commands_failed_total',
            ],
        ];
    }
}
