<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Request latency: average and p95, from the request duration histogram.
 */
class RequestDuration extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $sum = $this->metric('http_server_request_duration_milliseconds_sum');
        $count = $this->metric('http_server_request_duration_milliseconds_count');
        $bucket = $this->metric('http_server_request_duration_milliseconds_bucket');

        try {
            $totalTime = $this->total('sum(increase('.$sum.'['.$p.']))');
            $totalCount = $this->total('sum(increase('.$count.'['.$p.']))');
            $p95Now = $this->total('histogram_quantile(0.95, sum by (le) (rate('.$bucket.'['.$p.'])))');

            $avgRange = $this->metrics()->queryRange(
                'sum(rate('.$sum.'['.$w.'])) / sum(rate('.$count.'['.$w.']))',
                $start,
                $end,
            );

            $p95Range = $this->metrics()->queryRange(
                'histogram_quantile(0.95, sum by (le) (rate('.$bucket.'['.$w.'])))',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Duration', error: $exception->getMessage());
        }

        $series = [];

        if (isset($avgRange[0])) {
            $series[] = ['name' => 'AVG', 'data' => $avgRange[0]->toChartData(), 'color' => '#a1a1aa'];
        }

        if (isset($p95Range[0])) {
            $series[] = ['name' => 'P95', 'data' => $p95Range[0]->toChartData(), 'color' => '#fbbf24'];
        }

        return $this->chartCard(
            title: 'Duration',
            subtitle: 'Server-side request latency — average and 95th percentile',
            series: $series,
            stats: [
                $this->stat('AVG', $totalCount > 0 ? Format::ms($totalTime / $totalCount) : '—', 'dim'),
                $this->stat('P95', $totalCount > 0 && ! is_nan($p95Now) ? Format::ms($p95Now) : '—', 'warn'),
            ],
            unit: 'ms',
        );
    }
}
