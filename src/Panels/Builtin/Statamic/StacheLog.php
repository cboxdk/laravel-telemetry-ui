<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Panels\Builtin\SpanLog;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each Stache warm and clear (statamic-telemetry's `statamic.stache.*`
 * spans): when, how long the warm took to build, and what triggered it — a
 * deploy command, a CP save, a request that found it cold.
 */
final class StacheLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'stache';
    }

    protected function title(): string
    {
        return 'Warms and clears';
    }

    protected function subtitle(): string
    {
        return 'Each warm and clear, newest first: how long the build took, and what triggered it';
    }

    protected function empty(): string
    {
        return 'The Stache was not warmed or cleared in this period.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::re('name', 'statamic\\.stache\\.(warm|clear)')];
    }

    protected function attributes(): array
    {
        return [];
    }

    protected function columns(): array
    {
        return [Ui::col('operation', 'Operation')];
    }

    protected function slowMs(): float
    {
        return 10_000.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $operation = str_ends_with($span->name, 'clear') ? 'clear' : 'warm';

        return ['operation' => Ui::cell($operation, ['badge' => $operation, 'tone' => $operation === 'clear' ? 'warn' : null])];
    }
}
