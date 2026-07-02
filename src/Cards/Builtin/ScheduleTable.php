<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

final class ScheduleTable extends BreakdownTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Scheduled tasks',
            'keyLabel' => 'task',
            'keyColumn' => 'Task',
            'durationMetric' => 'schedule_task_duration_milliseconds',
            'outcomes' => [
                'processed' => 'schedule_tasks_processed_total',
                'failed' => 'schedule_tasks_failed_total',
                'skipped' => 'schedule_tasks_skipped_total',
            ],
        ];
    }
}
