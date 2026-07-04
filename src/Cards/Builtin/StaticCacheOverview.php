<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Static-cache outcomes (hit/miss/write/invalidate) per minute, from the
 * statamic.static_cache.operations counter emitted by
 * cboxdk/statamic-telemetry.
 */
final class StaticCacheOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();
        $metric = $this->metric('statamic_static_cache_operations_total');

        try {
            $totals = $this->metrics()->query('sum by (operation) (increase('.$metric.'['.$this->promDuration().']))');
            $range = $this->metrics()->queryRange('sum by (operation) (rate('.$metric.'['.$this->rateWindow().'])) * 60', $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Static Cache', error: $exception->getMessage());
        }

        $stats = [];
        foreach ($totals as $sample) {
            $op = $sample->labels['operation'] ?? '?';
            $stats[] = $this->stat(ucfirst($op), Format::count($sample->value), match ($op) {
                'hit' => 'ok',
                'miss' => 'warn',
                default => 'dim',
            });
        }

        return $this->chartCard(
            title: 'Static Cache',
            subtitle: 'Static cache operations per minute — hit, miss, write, invalidate',
            series: $this->toChartSeries($range, 'operation'),
            stats: $stats,
            unit: 'ops/min',
        );
    }
}
