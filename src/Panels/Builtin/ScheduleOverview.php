<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Scheduled task runs by outcome.
 */
final class ScheduleOverview extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        $outcomes = [
            'processed' => ['Processed', '#34d399', 'ok'],
            'skipped' => ['Skipped', '#71717a', 'dim'],
            'failed' => ['Failed', '#f87171', 'danger'],
        ];

        // A total and a chart per outcome, all sent together.
        $failure = null;
        $reads = [];

        foreach (array_keys($outcomes) as $outcome) {
            $metric = $this->metric('schedule_tasks_'.$outcome.'_total');
            $reads[$outcome] = [
                $this->later(fn (): float => $this->total($metric->increase($p)->sumBy())),
                $this->later(fn (): array => $this->metrics()->queryRange($metric->rate($w)->sumBy()->times(60), $start, $end)),
            ];
        }

        $this->settle($failure);

        if ($failure !== null) {
            return $this->chartCard('Scheduled tasks', error: $failure->getMessage());
        }

        foreach ($outcomes as $outcome => [$label, $color, $tone]) {
            $total = $reads[$outcome][0]->value() ?? 0.0;
            $range = $reads[$outcome][1]->value() ?? [];

            $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

            if (isset($range[0])) {
                $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
            }
        }

        return $this->chartCard(
            title: 'Scheduled tasks',
            subtitle: 'Scheduler task runs per minute by outcome (processed, skipped, failed)',
            series: $series,
            stats: $stats,
            type: 'bar',
            unit: 'runs/min',
            span: 2,
        );
    }
}
