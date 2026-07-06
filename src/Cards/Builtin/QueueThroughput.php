<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;

/**
 * Queue throughput: jobs processed per minute, per queue — the drain rate
 * to read against the backlog. From queue_metrics.queue.throughput.
 */
class QueueThroughput extends Card
{
    public function render(): View
    {
        return $this->promChart(
            title: 'Throughput',
            promql: 'sum by (queue) ('.$this->metric('queue_metrics_queue_throughput_per_minute').')',
            subtitle: 'Jobs processed per minute, per queue (60s window)',
            seriesLabel: 'queue',
            unit: 'jobs/min',
            stat: 'Now',
        );
    }
}
