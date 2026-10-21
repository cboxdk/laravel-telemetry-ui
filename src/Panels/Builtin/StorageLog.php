<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each filesystem operation (the `storage …` spans): which disk, what it did,
 * to which path, and what it was part of.
 */
final class StorageLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'disk';
    }

    protected function title(): string
    {
        return 'Storage operations';
    }

    protected function subtitle(): string
    {
        return 'Each read, write and delete, newest first: the disk, the path, and what did it';
    }

    protected function empty(): string
    {
        return 'No storage operations traced in this period. They are detail spans: with `telemetry.traces.details.mode` set to tail, only slow or failing traces keep them.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::nil('span.storage.operation')];
    }

    protected function attributes(): array
    {
        return ['span.storage.disk', 'span.storage.operation', 'span.storage.path'];
    }

    protected function columns(): array
    {
        return [Ui::col('operation', 'Operation'), Ui::col('path', 'Path')];
    }

    protected function slowMs(): float
    {
        return 500.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $operation = $this->str($attributes['storage.operation'] ?? null) ?? $span->name;

        return [
            'operation' => Ui::cell($operation, ['badge' => $operation]),
            'path' => Ui::cell($this->str($attributes['storage.path'] ?? null) ?? '—', array_filter([
                'mono' => true,
                'sub' => $this->str($attributes['storage.disk'] ?? null),
            ])),
        ];
    }
}
