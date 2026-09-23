<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;

/**
 * @phpstan-import-type Link from Ui
 */
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

    /** @return Link */
    protected function rowLink(string $name): array
    {
        return Ui::entity('command', $name);
    }

    /** @return Link */
    protected function failedLink(string $name): array
    {
        return Ui::explore('traces', ['laravel.command='.$name, 'status=error']);
    }
}
