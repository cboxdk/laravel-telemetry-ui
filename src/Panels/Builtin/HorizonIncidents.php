<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Horizon trouble signals in one chart: long queue waits, process restarts,
 * out-of-memory kills and migrated jobs — quiet fleet, flat lines.
 */
final class HorizonIncidents extends Panel
{
    /** metric → [series label, colour] */
    private const SIGNALS = [
        'horizon_long_wait_detected_total' => ['Long waits', '#fbbf24'],
        'horizon_process_restarts_total' => ['Restarts', '#60a5fa'],
        'horizon_process_out_of_memory_total' => ['Out of memory', '#f87171'],
        'horizon_jobs_migrated_total' => ['Migrated jobs', '#71717a'],
    ];

    public function data(): array
    {
        [$start, $end] = $this->range();

        $series = [];
        $stats = [];

        // A chart and a total per signal, all sent together.
        $failure = null;
        $reads = [];

        foreach (array_keys(self::SIGNALS) as $metric) {
            $reads[$metric] = [
                $this->later(fn (): array => $this->metrics()->queryRange(
                    $this->metric($metric)->rate($this->rateWindow())->sumBy()->times(60),
                    $start,
                    $end,
                )),
                $this->later(fn (): float => $this->total($this->metric($metric)->increase($this->promDuration())->sumBy())),
            ];
        }

        $this->settle($failure);

        if ($failure !== null) {
            return $this->chartCard('Horizon incidents', error: $failure->getMessage(), span: 2);
        }

        foreach (self::SIGNALS as $metric => [$label, $color]) {
            foreach ($reads[$metric][0]->value() ?? [] as $timeSeries) {
                $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
            }

            $total = $reads[$metric][1]->value() ?? 0.0;
            $stats[] = $this->stat($label, Format::count($total), $total > 0 && $label !== 'Migrated jobs' ? 'warn' : 'dim');
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
