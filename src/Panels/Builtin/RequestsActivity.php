<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;

/**
 * Request throughput with status-class breakdown (1/2/3XX grey, 4XX amber,
 * 5XX red) — the Nightwatch "Activity / Requests" card.
 */
class RequestsActivity extends Panel
{
    protected ?string $drillPage = 'requests';

    public function data(): array
    {
        [$start, $end] = $this->range();

        $count = $this->metric('http_server_request_duration_seconds_count');

        try {
            $totals = $this->metrics()->query($count->increase($this->promDuration())->sumBy('http_response_status_code'));
            $range = $this->metrics()->queryRange($count->rate($this->rateWindow())->sumBy('http_response_status_code')->times(60), $start, $end);
            // Same period-total, evaluated at the window's start = the immediately
            // preceding equal window, for the throughput delta.
            $previous = $this->metrics()->query($count->increase($this->promDuration())->sumBy(), $start);
        } catch (SourceException $exception) {
            return $this->chartCard($this->activityTitle(), error: $exception->getMessage());
        }

        $classTotals = ['ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0];

        foreach ($totals as $sample) {
            $classTotals[$this->bucket($sample->labels['http_response_status_code'] ?? '')] += $sample->value;
        }

        $total = array_sum($classTotals);
        $previousTotal = array_sum(array_map(static fn ($sample): float => $sample->value, $previous));

        return $this->chartCard(
            title: $this->activityTitle(),
            subtitle: 'Incoming HTTP requests per minute, split by response status class',
            series: $this->bucketedSeries($range),
            stats: [
                $this->statDelta('Requests', Format::count($total), $total, $previousTotal, upIsGood: true, link: Ui::explore('requests'), points: $this->throughputPoints($range)),
                $this->stat('1/2/3XX', Format::count($classTotals['ok']), 'dim', Ui::explore('requests', ['http.response.status_code<400'])),
                $this->stat('4XX', Format::count($classTotals['4xx']), $classTotals['4xx'] > 0 ? 'warn' : 'dim', Ui::explore('requests', ['http.response.status_code>=400', 'http.response.status_code<500'])),
                $this->stat('5XX', Format::count($classTotals['5xx']), $classTotals['5xx'] > 0 ? 'danger' : 'dim', Ui::explore('requests', ['http.response.status_code>=500'])),
            ],
            type: 'bar',
            unit: 'req/min',
        );
    }

    /**
     * Merge per-class series into ok/4xx/5xx buckets, point-by-point.
     *
     * @param  list<TimeSeries>  $range
     * @return list<array{name: string, data: list<array{float, float}>, color: string}>
     */
    private function bucketedSeries(array $range): array
    {
        /** @var array<string, array<int, float>> $buckets */
        $buckets = [];

        foreach ($range as $series) {
            $bucket = $this->bucket($series->labels['http_response_status_code'] ?? '');

            foreach ($series->points as $point) {
                $key = (int) $point->timestamp;
                $buckets[$bucket][$key] = ($buckets[$bucket][$key] ?? 0.0) + $point->value;
            }
        }

        $meta = [
            'ok' => ['1/2/3XX', '#52525b'],
            '4xx' => ['4XX', '#fbbf24'],
            '5xx' => ['5XX', '#f87171'],
        ];

        $result = [];

        foreach ($meta as $bucket => [$name, $color]) {
            if (! isset($buckets[$bucket])) {
                continue;
            }

            ksort($buckets[$bucket]);

            $data = [];

            foreach ($buckets[$bucket] as $timestamp => $value) {
                $data[] = [$timestamp * 1000.0, $value];
            }

            $result[] = ['name' => $name, 'data' => $data, 'color' => $color];
        }

        return $result;
    }

    /**
     * Total throughput per time bucket (all status classes summed) — the
     * value list behind the headline tile's sparkline.
     *
     * @param  list<TimeSeries>  $range
     * @return list<float>
     */
    private function throughputPoints(array $range): array
    {
        /** @var array<int, float> $totals */
        $totals = [];

        foreach ($range as $series) {
            foreach ($series->points as $point) {
                $key = (int) $point->timestamp;
                $totals[$key] = ($totals[$key] ?? 0.0) + $point->value;
            }
        }

        ksort($totals);

        return array_values($totals);
    }

    private function bucket(string $code): string
    {
        return match ($code === '' ? '' : $code[0].'xx') {
            '4xx' => '4xx',
            '5xx' => '5xx',
            default => 'ok',
        };
    }

    /** What this throughput chart is about — a route family names itself. */
    protected function activityTitle(): string
    {
        return 'Requests';
    }
}
