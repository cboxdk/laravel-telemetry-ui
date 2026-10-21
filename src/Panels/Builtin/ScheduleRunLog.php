<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each scheduled task run (the `schedule …` spans): which task, on which
 * cron, how it ended. Runs the scheduler skipped (filters, overlap locks) are
 * not runs, so they are counted on the chart rather than listed here.
 */
final class ScheduleRunLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'sched';
    }

    protected function title(): string
    {
        return 'Task runs';
    }

    protected function subtitle(): string
    {
        return 'Each run, newest first: the task, its schedule, and how it ended';
    }

    protected function empty(): string
    {
        return 'No scheduled task runs traced in this period.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::nil('span.schedule.task')];
    }

    protected function attributes(): array
    {
        return ['span.schedule.task', 'span.schedule.cron'];
    }

    protected function columns(): array
    {
        return [Ui::col('task', 'Task')];
    }

    protected function slowMs(): float
    {
        return 60_000.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        return [
            'task' => Ui::cell($this->str($attributes['schedule.task'] ?? null) ?? $span->name, array_filter([
                'mono' => true,
                'title' => $this->str($attributes['schedule.cron'] ?? null),
            ])),
        ];
    }
}
