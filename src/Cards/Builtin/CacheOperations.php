<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Cache operations by outcome, plus the hit ratio.
 */
final class CacheOperations extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('cache_operations_total');
        $p = $this->promDuration();

        try {
            $totals = $this->metrics()->query('sum by (operation) (increase('.$metric.'['.$p.']))');

            $range = $this->metrics()->queryRange(
                'sum by (operation) (rate('.$metric.'['.$this->rateWindow().'])) * 60',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Cache', error: $exception->getMessage());
        }

        $byOperation = [];

        foreach ($totals as $sample) {
            $byOperation[$sample->labels['operation'] ?? '?'] = $sample->value;
        }

        $hits = $byOperation['hit'] ?? 0.0;
        $misses = $byOperation['miss'] ?? 0.0;
        $lookups = $hits + $misses;

        $colors = ['hit' => '#34d399', 'miss' => '#fbbf24', 'write' => '#60a5fa', 'forget' => '#71717a'];

        $series = array_map(static fn (TimeSeries $s): array => [
            'name' => $s->labels['operation'] ?? '?',
            'data' => $s->toChartData(),
            'color' => $colors[$s->labels['operation'] ?? ''] ?? '#c084fc',
        ], $range);

        return $this->chartCard(
            title: 'Cache operations',
            subtitle: 'Cache hits, misses and writes per minute, with the overall hit ratio',
            series: $series,
            stats: [
                $this->stat('Hit ratio', $lookups > 0 ? Format::percent($hits / $lookups) : '—', 'ok'),
                $this->stat('Hits', Format::count($hits), 'dim'),
                $this->stat('Misses', Format::count($misses), $misses > $hits ? 'warn' : 'dim'),
                $this->stat('Writes', Format::count($byOperation['write'] ?? 0.0), 'dim'),
            ],
            type: 'area',
            unit: 'ops/min',
            span: 2,
            note: 'Cache instrumentation is opt-in: TELEMETRY_INSTRUMENT_CACHE=true.',
        );
    }
}
