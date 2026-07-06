<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Horizon trouble signals in one chart: long queue waits, process restarts,
 * out-of-memory kills and migrated jobs — quiet fleet, flat lines.
 */
final class HorizonIncidents extends Card
{
    /** metric → [series label, colour] */
    private const SIGNALS = [
        'horizon_long_wait_detected_total' => ['Long waits', '#fbbf24'],
        'horizon_process_restarts_total' => ['Restarts', '#60a5fa'],
        'horizon_process_out_of_memory_total' => ['Out of memory', '#f87171'],
        'horizon_jobs_migrated_total' => ['Migrated jobs', '#71717a'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = [];
        $stats = [];

        try {
            foreach (self::SIGNALS as $metric => [$label, $color]) {
                $range = $this->metrics()->queryRange(
                    'sum(rate('.$this->metric($metric).'['.$this->rateWindow().'])) * 60',
                    $start,
                    $end,
                );

                foreach ($range as $timeSeries) {
                    $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
                }

                $total = $this->total('sum(increase('.$this->metric($metric).'['.$this->promDuration().']))');
                $stats[] = $this->stat($label, Format::count($total), $total > 0 && $label !== 'Migrated jobs' ? 'warn' : 'dim');
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Horizon incidents', error: $exception->getMessage(), span: 2);
        }

        return $this->chartCard(
            title: 'Horizon incidents',
            subtitle: 'Long waits, worker restarts, OOM kills and queue migrations per minute',
            series: $series,
            stats: $stats,
            type: 'line',
            unit: '/min',
            span: 2,
        );
    }
}
