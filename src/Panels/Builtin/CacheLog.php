<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each cache miss, write and forget (the `cache.*` spans) — which key, which
 * store, and what asked. Hits are left out: they are most of the traffic and
 * none of the trouble; the chart above counts them.
 */
final class CacheLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'cache';
    }

    protected function title(): string
    {
        return 'Cache misses and writes';
    }

    protected function subtitle(): string
    {
        return 'Each miss, write and forget, newest first: the key, the store, and what asked';
    }

    protected function empty(): string
    {
        return 'No cache misses or writes traced in this period. They are detail spans: with `telemetry.traces.details.mode` set to tail, only slow or failing traces keep them.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::nil('span.cache.operation'), TraceCondition::neq('span.cache.operation', 'hit')];
    }

    protected function attributes(): array
    {
        return ['span.cache.operation', 'span.cache.key', 'span.cache.store', 'span.cache.key.group'];
    }

    protected function columns(): array
    {
        return [Ui::col('operation', 'Operation'), Ui::col('key', 'Key')];
    }

    protected function slowMs(): float
    {
        return 100.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $operation = $this->str($attributes['cache.operation'] ?? null) ?? $span->name;

        return [
            'operation' => Ui::cell($operation, ['badge' => $operation, 'tone' => $operation === 'miss' ? 'warn' : null]),
            'key' => Ui::cell($this->str($attributes['cache.key'] ?? null) ?? '—', array_filter([
                'mono' => true,
                'title' => implode(' · ', array_filter([
                    $this->str($attributes['cache.store'] ?? null),
                    $this->str($attributes['cache.key.group'] ?? null),
                ])),
            ])),
        ];
    }
}
