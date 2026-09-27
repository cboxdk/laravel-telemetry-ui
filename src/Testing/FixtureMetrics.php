<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use Cbox\TelemetryUi\Connectors\ProbeResult;
use Cbox\TelemetryUi\Contracts\EnumeratesMetricNames;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\ProbesConnection;
use Cbox\TelemetryUi\Queries\Ir\MetricAgg;
use Cbox\TelemetryUi\Queries\Ir\MetricFn;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\DataPoint;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use DateTimeInterface;

/**
 * A metrics backend that answers from arithmetic. See {@see FixtureData}.
 */
final class FixtureMetrics implements EnumeratesMetricNames, MetricsSource, ProbesConnection
{
    public function __construct(private readonly FixtureData $data = new FixtureData) {}

    public function query(MetricQuery $query, ?DateTimeInterface $at = null): array
    {
        $timestamp = ($at ?? $this->data->clock())->getTimestamp();

        return array_values(array_map(
            fn (array $labels): Sample => new Sample(
                $labels,
                (float) $timestamp,
                $this->data->value($this->metricName($query), $labels, $timestamp, $this->counting($query)),
            ),
            $this->seriesLabels($query),
        ));
    }

    public function queryRange(
        MetricQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $step = null,
    ): array {
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $step ??= max(15, (int) floor(max(1, $to - $from) / 120));
        $metric = $this->metricName($query);
        $counting = $this->counting($query);

        return array_values(array_map(function (array $labels) use ($metric, $counting, $from, $to, $step): TimeSeries {
            $points = [];

            for ($at = $from; $at <= $to; $at += $step) {
                $points[] = new DataPoint((float) $at, $this->data->value($metric, $labels, $at, $counting));
            }

            return new TimeSeries($labels, $points);
        }, $this->seriesLabels($query)));
    }

    public function labelValues(
        string $label,
        ?string $match = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array {
        return $this->data->labelValues($label);
    }

    public function metricNamesMatching(array $patterns, string $scope = ''): array
    {
        // Yes to every pattern a page probes for, so every autodetected page
        // appears in the navigation. A fixture that hid half the sidebar
        // would be a fixture nobody could screenshot.
        $names = [];

        foreach ($patterns as $pattern) {
            $name = trim(str_replace(['*', '%'], '', (string) $pattern));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function probe(): ProbeResult
    {
        return ProbeResult::pass('fixture');
    }

    /**
     * Whether the query is asking "how many", which the metric's NAME
     * cannot answer: `increase(http_server_request_duration_count[1h])`
     * counts requests however the histogram is spelled.
     */
    private function counting(MetricQuery $query): bool
    {
        return $query->agg === MetricAgg::Count
            || $query->fn !== MetricFn::None
            || str_ends_with($query->name, '_count')
            || str_ends_with($query->name, '.count');
    }

    private function metricName(MetricQuery $query): string
    {
        return $query->name !== '' ? $query->name : (string) $query->raw;
    }

    /**
     * One label set per value of whatever the query groups by, so a legend
     * reads like a legend. An ungrouped query gets a single series.
     *
     * @return list<array<string, string>>
     */
    private function seriesLabels(MetricQuery $query): array
    {
        if ($query->by === []) {
            return [[]];
        }

        $sets = [[]];

        foreach (array_slice($query->by, 0, 2) as $label) {
            $values = array_slice($this->data->labelValues($label), 0, 5);
            $next = [];

            foreach ($sets as $set) {
                foreach ($values as $value) {
                    $next[] = $set + [$label => $value];
                }
            }

            $sets = $next;
        }

        return array_slice($sets, 0, 8);
    }
}
