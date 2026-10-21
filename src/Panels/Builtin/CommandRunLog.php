<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each artisan command run, with its exit code (the `artisan …` spans). A
 * non-zero exit is a failed run.
 */
final class CommandRunLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'cmd';
    }

    protected function title(): string
    {
        return 'Command runs';
    }

    protected function subtitle(): string
    {
        return 'Each run, newest first: the command, its exit code and how long it took';
    }

    protected function empty(): string
    {
        return 'No command runs traced in this period. Commands are traced with `telemetry.instrument.commands` on.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::nil('span.laravel.command')];
    }

    protected function attributes(): array
    {
        return ['span.laravel.command', 'span.laravel.command.exit_code'];
    }

    protected function columns(): array
    {
        return [Ui::col('command', 'Command'), Ui::num('exit', 'Exit')];
    }

    protected function slowMs(): float
    {
        return 60_000.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $exit = $this->str($attributes['laravel.command.exit_code'] ?? null);
        $command = $this->str($attributes['laravel.command'] ?? null);

        return [
            'command' => Ui::cell($command ?? $span->name, ['mono' => true, ...($command !== null ? ['link' => Ui::entity('command', $command)] : [])]),
            'exit' => $exit === null
                ? Ui::cell('—', ['tone' => 'dim'])
                : Ui::cell($exit, ['mono' => true, 'raw' => (int) $exit, 'tone' => $exit === '0' ? null : 'danger']),
        ];
    }
}
