<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Scheduled task runs by outcome.
 */
final class ScheduleOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->period()->promDuration();
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

                $total = $this->total('sum(increase('.$metric.'['.$p.']))');
                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange('sum(rate('.$metric.'['.$w.'])) * 60', $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Scheduled tasks', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Scheduled tasks',
            series: $series,
            stats: $stats,
            type: 'bar',
            unit: 'runs/min',
            span: 2,
        );
    }
}
