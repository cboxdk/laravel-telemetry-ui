<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Worker fleet: busy vs idle workers over time, with current utilization.
 * From queue_metrics.workers.count and queue_metrics.workers.utilization.
 */
final class QueueWorkers extends Panel
{
    protected ?string $drillPage = 'queues';

    private const STATES = [
        'busy' => ['Busy', '#34d399'],
        'idle' => ['Idle', '#71717a'],
    ];

    public function data(): array
    {
        [$start, $end] = $this->range();

        $count = $this->metric('queue_metrics_workers_count');

        try {
            $now = [];

            foreach ($this->metrics()->query($count->sumBy('state')) as $sample) {
                $now[$sample->labels['state'] ?? ''] = $sample->value;
            }

            $utilization = $this->total(
                $this->metric('queue_metrics_workers_utilization_percent', 'window="current"')->avgBy(),
            );

            $range = $this->metrics()->queryRange($count->sumBy('state'), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Workers', error: $exception->getMessage());
        }

        $series = [];
        $stats = [];

        foreach (self::STATES as $state => [$label, $color]) {
            $stats[] = $this->stat($label, Format::count($now[$state] ?? 0.0), ($now[$state] ?? 0.0) > 0 ? null : 'dim');

            foreach ($range as $timeSeries) {
                if (($timeSeries->labels['state'] ?? '') === $state) {
                    $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
                }
            }
        }

        $stats[] = $this->stat('Utilization', Format::percent($utilization / 100), $utilization >= 90 ? 'warn' : null);

        return $this->chartCard(
            title: 'Workers',
            subtitle: 'Queue workers by state, with current fleet utilization',
            series: $series,
            stats: $stats,
            type: 'area',
            unit: 'workers',
        );
    }
}
