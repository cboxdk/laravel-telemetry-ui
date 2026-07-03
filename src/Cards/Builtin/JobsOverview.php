<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Queue job outcomes: processed, released (retried) and failed.
 */
final class JobsOverview extends Card
{
    private const OUTCOMES = [
        'processed' => ['Processed', '#34d399', 'ok'],
        'released' => ['Released', '#fbbf24', 'warn'],
        'failed' => ['Failed', '#f87171', 'danger'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        try {
            foreach (self::OUTCOMES as $outcome => [$label, $color, $tone]) {
                $metric = $this->metric('queue_jobs_'.$outcome.'_total');

                $total = $this->total('sum(increase('.$metric.'['.$p.']))');

                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange('sum(rate('.$metric.'['.$w.'])) * 60', $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Jobs', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Jobs',
            subtitle: 'Queue job outcomes per minute: processed, released (retried), failed',
            series: $series,
            stats: $stats,
            type: 'bar',
            unit: 'jobs/min',
        );
    }
}
