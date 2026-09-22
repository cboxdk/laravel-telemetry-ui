<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;

/**
 * Request latency: average and p95, from the request duration histogram.
 */
class RequestDuration extends Panel
{
    protected ?string $drillPage = 'requests';

    public function data(): array
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

            // Sum and count as two queries, divided here: one binary PromQL
            // expression holds both sides at once, and a day of per-route
            // series is over what some backends (telemetryd) allow per query.
            $sumRange = $this->metrics()->queryRange($sum->rate($w)->sumBy(), $start, $end);
            $countRange = $this->metrics()->queryRange($count->rate($w)->sumBy(), $start, $end);
            $avgData = self::ratioSeries($sumRange[0] ?? null, $countRange[0] ?? null, 1000.0);

            $p95Range = $this->metrics()->queryRange(
                $bucket->quantile(0.95, $w)->times(1000),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Duration', error: $exception->getMessage());
        }

        $series = [];

        if ($avgData !== []) {
            $series[] = ['name' => 'AVG', 'data' => $avgData, 'color' => '#a1a1aa'];
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

    /**
     * numerator / denominator per shared timestamp, × factor, as chart points;
     * points where the denominator is zero are skipped (no requests ≠ 0 ms).
     *
     * @return list<array{float, float}>
     */
    private static function ratioSeries(?TimeSeries $numerator, ?TimeSeries $denominator, float $factor): array
    {
        if ($numerator === null || $denominator === null) {
            return [];
        }

        $den = [];

        foreach ($denominator->points as $point) {
            $den[(string) $point->timestamp] = $point->value;
        }

        $out = [];

        foreach ($numerator->points as $point) {
            $d = $den[(string) $point->timestamp] ?? 0.0;

            if ($d > 0.0 && ! is_nan($point->value)) {
                $out[] = [$point->timestamp * 1000.0, $point->value / $d * $factor];
            }
        }

        return $out;
    }
}
