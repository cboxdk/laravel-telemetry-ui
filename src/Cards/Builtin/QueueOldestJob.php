<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Oldest pending job age, per queue — how far behind each queue is running.
 * From queue_metrics.queue.oldest_job.age.
 */
final class QueueOldestJob extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $age = $this->metric('queue_metrics_queue_oldest_job_age_seconds');

        try {
            $oldest = 0.0;

            foreach ($this->metrics()->query('max by (queue) ('.$age.')') as $sample) {
                $oldest = max($oldest, $sample->value);
            }

            $range = $this->metrics()->queryRange('max by (queue) ('.$age.')', $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Oldest job', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Oldest job',
            subtitle: 'Age of the oldest pending job, per queue — how far behind each queue runs',
            series: $this->toChartSeries($range, 'queue'),
            stats: [
                $this->stat('Oldest', $oldest > 0 ? Format::ms($oldest * 1000) : '—', $oldest > 0 ? 'warn' : 'dim'),
            ],
            unit: 's',
        );
    }
}
