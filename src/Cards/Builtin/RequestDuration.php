<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Request latency: average and p95, from the request duration histogram.
 */
class RequestDuration extends Card
{
    protected ?string $drillPage = 'requests';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $sum = $this->metric('http_server_request_duration_seconds_sum');
        $count = $this->metric('http_server_request_duration_seconds_count');
        $bucket = $this->metric('http_server_request_duration_seconds_bucket');

        try {
            $totalTime = $this->total($sum->increase($p)->sumBy());
            $totalCount = $this->total($count->increase($p)->sumBy());
            $p95Now = $this->total($bucket->quantile(0.95, $p));

            $compiler = new PromqlCompiler;
            $sumSelector = $compiler->compile($sum);
            $countSelector = $compiler->compile($count);

            // v2 records this histogram in SECONDS; ×1000 keeps the whole card
            // (chart unit, Format::ms, thresholds) in milliseconds as before.
            $avgRange = $this->metrics()->queryRange(
                MetricQuery::raw('1000 * (sum(rate('.$sumSelector.'['.$w.'])) / sum(rate('.$countSelector.'['.$w.'])))'),
                $start,
                $end,
            );

            $p95Range = $this->metrics()->queryRange(
                $bucket->quantile(0.95, $w)->times(1000),
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
                $this->stat('AVG', $totalCount > 0 ? Format::ms($totalTime / $totalCount * 1000) : '—', 'dim'),
                $this->stat('P95', $totalCount > 0 && ! is_nan($p95Now) ? Format::ms($p95Now * 1000) : '—', 'warn'),
            ],
            unit: 'ms',
        );
    }
}
