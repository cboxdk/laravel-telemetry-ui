<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Scheduled task runs by outcome.
 */
final class ScheduleOverview extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        try {
            foreach ([
                'processed' => ['Processed', '#34d399', 'ok'],
                'skipped' => ['Skipped', '#71717a', 'dim'],
                'failed' => ['Failed', '#f87171', 'danger'],
            ] as $outcome => [$label, $color, $tone]) {
                $metric = $this->metric('schedule_tasks_'.$outcome.'_total');

                $total = $this->total($metric->increase($p)->sumBy());
                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange($metric->rate($w)->sumBy()->times(60), $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Scheduled tasks', error: $exception->getMessage());
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
