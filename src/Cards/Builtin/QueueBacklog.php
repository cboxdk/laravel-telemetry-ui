<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Queue backlog: jobs sitting in the queues by state — pending (waiting for
 * a worker), scheduled (delayed) and reserved (being processed right now).
 * From cboxdk/laravel-queue-metrics' queue_metrics.queue.depth gauge.
 */
class QueueBacklog extends Card
{
    protected ?string $drillPage = 'queues';

    private const STATES = [
        'pending' => ['Pending', '#60a5fa'],
        'scheduled' => ['Scheduled', '#c084fc'],
        'reserved' => ['Reserved', '#fbbf24'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $depth = $this->metric('queue_metrics_queue_depth');

        try {
            $now = [];

            foreach ($this->metrics()->query('sum by (state) ('.$depth.')') as $sample) {
                $now[$sample->labels['state'] ?? ''] = $sample->value;
            }

            $range = $this->metrics()->queryRange('sum by (state) ('.$depth.')', $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Backlog', error: $exception->getMessage());
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

        return $this->chartCard(
            title: 'Backlog',
            subtitle: 'Jobs in the queues by state: pending, scheduled (delayed) and reserved (in flight)',
            series: $series,
            stats: $stats,
            type: 'area',
            unit: 'jobs',
        );
    }
}
