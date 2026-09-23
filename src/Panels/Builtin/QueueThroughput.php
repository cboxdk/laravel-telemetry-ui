<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;

/**
 * Queue throughput: jobs processed per minute, per queue — the drain rate
 * to read against the backlog. From queue_metrics.queue.throughput.
 */
class QueueThroughput extends Panel
{
    protected ?string $drillPage = 'queues';

    public function data(): array
    {
        return $this->promChart(
            title: 'Throughput',
            promql: $this->metric('queue_metrics_queue_throughput_per_min')->sumBy('queue'),
            subtitle: 'Jobs processed per minute, per queue (60s window)',
            seriesLabel: 'queue',
            unit: 'jobs/min',
            stat: 'Now',
        );
    }
}
