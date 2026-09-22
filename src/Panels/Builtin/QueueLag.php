<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Queue lag: p95 time from dispatch to execution, per queue — the number
 * that tells you when to scale workers.
 */
final class QueueLag extends Panel
{
    protected ?string $drillPage = 'jobs';

    public function data(): array
    {
        [$start, $end] = $this->range();

        $bucket = $this->metric('queue_job_wait_time_milliseconds_bucket');

        try {
            $p95Now = $this->total(
                $bucket->quantile(0.95, $this->promDuration()),
            );

            $range = $this->metrics()->queryRange(
                $bucket->quantile(0.95, $this->rateWindow(), 'queue'),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Queue lag', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Queue lag (P95 wait)',
            subtitle: 'Time a job waits in the queue before a worker starts it (dispatch → execution)',
            series: $this->toChartSeries($range, 'queue'),
            stats: [
                $this->stat('P95 wait', is_nan($p95Now) ? '—' : Format::ms($p95Now), 'warn'),
            ],
            unit: 'ms',
        );
    }
}
