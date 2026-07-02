<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Request throughput with status-class breakdown (1/2/3XX grey, 4XX amber,
 * 5XX red) — the Nightwatch "Activity / Requests" card.
 */
final class RequestsActivity extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $count = $this->metric('http_server_request_duration_milliseconds_count');

        $byClass = static fn (string $inner): string => 'sum by (class) (label_replace('.$inner.', "class", "${1}xx", "http_response_status_code", "([0-9]).."))';

        try {
            $totals = $this->metrics()->query($byClass('increase('.$count.'['.$this->promDuration().'])'));
            $range = $this->metrics()->queryRange($byClass('rate('.$count.'['.$this->rateWindow().'])').' * 60', $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Requests', error: $exception->getMessage());
        }

        $classTotals = ['ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0];

        foreach ($totals as $sample) {
            $classTotals[$this->bucket($sample->labels['class'] ?? '')] += $sample->value;
        }

        return $this->chartCard(
            title: 'Requests',
            series: $this->bucketedSeries($range),
            stats: [
                $this->stat('Requests', Format::count(array_sum($classTotals))),
                $this->stat('1/2/3XX', Format::count($classTotals['ok']), 'dim'),
                $this->stat('4XX', Format::count($classTotals['4xx']), $classTotals['4xx'] > 0 ? 'warn' : 'dim'),
                $this->stat('5XX', Format::count($classTotals['5xx']), $classTotals['5xx'] > 0 ? 'danger' : 'dim'),
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
            $bucket = $this->bucket($series->labels['class'] ?? '');

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

    private function bucket(string $class): string
    {
        return match ($class) {
            '4xx' => '4xx',
            '5xx' => '5xx',
            default => 'ok',
        };
    }
}
