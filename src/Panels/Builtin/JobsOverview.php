<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
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

        // A total and a chart per outcome, all sent together.
        $failure = null;
        $reads = [];

        foreach (self::OUTCOMES as $outcome => $_) {
            $metric = $this->metric('queue_jobs_'.$outcome.'_total');
            $reads[$outcome] = [
                $this->later(fn (): float => $this->total($metric->increase($p)->sumBy())),
                $this->later(fn (): array => $this->metrics()->queryRange($metric->rate($w)->sumBy()->times(60), $start, $end)),
            ];
        }

        $this->settle($failure);

        if ($failure !== null) {
            return $this->chartCard('Jobs', error: $failure->getMessage());
        }

        foreach (self::OUTCOMES as $outcome => [$label, $color, $tone]) {
            $total = $reads[$outcome][0]->value() ?? 0.0;
            $range = $reads[$outcome][1]->value() ?? [];

            $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

            if (isset($range[0])) {
                $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
            }
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

    protected function statLinks(): array
    {
        return [
            'Processed' => Ui::explore('traces', ['laravel.job.class!=']),
            'Released' => Ui::entityIndex('job'),
            'Failed' => Ui::explore('traces', ['laravel.job.class!=', 'status=error']),
        ];
    }
}
