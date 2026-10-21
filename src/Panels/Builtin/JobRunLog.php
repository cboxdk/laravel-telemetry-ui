<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each job run — one row per attempt: which job, on which queue, which
 * attempt, how it ended and how long it ran (the queue worker's `process`
 * spans).
 */
class JobRunLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'jobs';
    }

    protected function title(): string
    {
        return 'Job runs';
    }

    protected function subtitle(): string
    {
        return 'Each attempt, newest first: the job, its queue and attempt, and how it ended';
    }

    protected function empty(): string
    {
        return 'No job runs traced in this period.';
    }

    /**
     * Narrows the runs further (the queue-detail page: one queue).
     *
     * @return list<TraceCondition>
     */
    protected function narrow(): array
    {
        return [];
    }

    protected function conditions(): array
    {
        return [TraceCondition::eq('span.messaging.operation.type', 'process'), ...$this->narrow()];
    }

    protected function attributes(): array
    {
        return ['span.laravel.job.class', 'span.messaging.destination.name', 'span.laravel.job.attempts', 'span.queue.job.outcome'];
    }

    protected function columns(): array
    {
        return [Ui::col('job', 'Job'), Ui::col('queue', 'Queue'), Ui::num('attempt', 'Try')];
    }

    protected function slowMs(): float
    {
        return 10_000.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $class = $this->str($attributes['laravel.job.class'] ?? null) ?? $span->name;
        $attempt = (int) $this->num($attributes['laravel.job.attempts'] ?? null);
        $outcome = $this->str($attributes['queue.job.outcome'] ?? null);

        $queue = $this->str($attributes['messaging.destination.name'] ?? null);

        return [
            'job' => Ui::cell(class_basename($class), array_filter([
                'mono' => true,
                'link' => Ui::entity('job', $class),
                'title' => implode(' · ', array_filter([$class, $outcome])),
            ])),
            'queue' => $queue !== null ? Ui::cell($queue, ['mono' => true, 'link' => Ui::entity('queue', $queue)]) : Ui::cell('—', ['tone' => 'dim']),
            'attempt' => Ui::cell($attempt > 0 ? $attempt : '—', ['raw' => $attempt, 'mono' => true, 'tone' => $attempt > 1 ? 'warn' : null]),
        ];
    }
}
