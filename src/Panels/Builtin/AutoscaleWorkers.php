<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Autoscaler steering: the worker count the autoscaler is steering toward
 * (queue_autoscale.workers.target) against the workers actually attached
 * (queue-metrics' queue_metrics.queue.active_workers) — the pair the
 * autoscale package's docs say to join in dashboards.
 */
class AutoscaleWorkers extends Panel
{
    protected ?string $drillPage = 'autoscale';

    public function data(): array
    {
        [$start, $end] = $this->range();

        $target = $this->metric('queue_autoscale_workers_target')->sumBy();
        $active = $this->metric('queue_metrics_queue_active_workers')->sumBy();

        try {
            $targetNow = $this->total($target);
            $activeNow = $this->total($active);

            $targetRange = $this->metrics()->queryRange($target, $start, $end);
            $activeRange = $this->metrics()->queryRange($active, $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Workers: target vs active', error: $exception->getMessage());
        }

        $series = [];

        if (isset($targetRange[0])) {
            $series[] = ['name' => 'Target', 'data' => $targetRange[0]->toChartData(), 'color' => '#60a5fa'];
        }

        if (isset($activeRange[0])) {
            $series[] = ['name' => 'Active', 'data' => $activeRange[0]->toChartData(), 'color' => '#34d399'];
        }

        return $this->chartCard(
            title: 'Workers: target vs active',
            subtitle: 'Worker count the autoscaler steers toward vs workers actually attached',
            series: $series,
            stats: [
                $this->stat('Target', Format::count($targetNow), null),
                $this->stat('Active', Format::count($activeNow), $activeNow < $targetNow ? 'warn' : 'ok'),
            ],
            unit: 'workers',
        );
    }
}
