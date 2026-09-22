<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Queue job outcomes: processed, released (retried) and failed.
 */
class JobsOverview extends Panel
{
    protected ?string $drillPage = 'jobs';

    private const OUTCOMES = [
        'processed' => ['Processed', '#34d399', 'ok'],
        'released' => ['Released', '#fbbf24', 'warn'],
        'failed' => ['Failed', '#f87171', 'danger'],
    ];

    public function data(): array
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        try {
            foreach (self::OUTCOMES as $outcome => [$label, $color, $tone]) {
                $metric = $this->metric('queue_jobs_'.$outcome.'_total');

                $total = $this->total($metric->increase($p)->sumBy());

                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange($metric->rate($w)->sumBy()->times(60), $start, $end);

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
