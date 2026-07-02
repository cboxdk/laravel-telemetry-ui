<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Queue lag: p95 time from dispatch to execution, per queue — the number
 * that tells you when to scale workers.
 */
final class QueueLag extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $bucket = $this->metric('queue_job_wait_time_milliseconds_bucket');

        try {
            $p95Now = $this->total(
                'histogram_quantile(0.95, sum by (le) (rate('.$bucket.'['.$this->period()->promDuration().'])))',
            );

            $range = $this->metrics()->queryRange(
                'histogram_quantile(0.95, sum by (queue, le) (rate('.$bucket.'['.$this->rateWindow().'])))',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Queue lag', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Queue lag (P95 wait)',
            series: $this->toChartSeries($range, 'queue'),
            stats: [
                $this->stat('P95 wait', is_nan($p95Now) ? '—' : Format::ms($p95Now), 'warn'),
            ],
            unit: 'ms',
        );
    }
}
