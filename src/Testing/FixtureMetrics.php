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
        $scale = $query->scalar ?? 1.0;
        $filters = $this->filterLabels($query);

        return array_values(array_map(
            fn (array $labels): Sample => new Sample(
                $labels,
                (float) $timestamp,
                $scale * $this->data->value(
                    $this->metricName($query),
                    $labels + $filters,
                    $timestamp,
                    $this->counting($query),
                    $query->quantile,
                    $this->countScale($query),
                ),
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
        $quantile = $query->quantile;
        $countScale = $this->countScale($query);
        $filters = $this->filterLabels($query);

        // `->times(1000)` is how a panel converts seconds to the
        // milliseconds it declares as its unit, and ignoring it returned
        // a p95 series a thousand times too small — a chart in µs beside a
        // stat in ms, from the same query. A driver that drops part of the
        // IR is not a driver; it is a coincidence.
        $scale = $query->scalar ?? 1.0;

        return array_values(array_map(function (array $labels) use ($metric, $counting, $quantile, $scale, $countScale, $filters, $from, $to, $step): TimeSeries {
            $points = [];

            for ($at = $from; $at <= $to; $at += $step) {
                $points[] = new DataPoint(
                    (float) $at,
                    $scale * $this->data->value($metric, $labels + $filters, $at, $counting, $quantile, $countScale),
                );
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
        // A quantile is never a count — `histogram_quantile(0.95,
        // rate(http_server_request_duration_bucket[5m]))` is a latency,
        // and it carries a rate() like every other histogram read. Taking
        // `fn !== None` as proof of counting gave the duration panel a
        // y-axis in tens of SECONDS beside a stat reading 28.5ms, which
        // is exactly the kind of quietly wrong picture a fixture must
        // not produce.
        if ($query->quantile !== null) {
            return false;
        }

        $name = strtolower($this->metricName($query));

        // A raw expression carries its own range function.
        if ($query->raw !== null && preg_match('/\b(rate|irate|increase|delta)\s*\(/i', $query->raw) === 1) {
            return ! str_contains($name, 'duration') && ! str_contains($name, 'latency');
        }

        if (str_contains($name, 'duration') || str_contains($name, 'latency')) {
            return str_ends_with($name, '_count') || str_ends_with($name, '.count');
        }

        // A `_count` or `_total` SUFFIX only means "counting" when the query
        // actually counts with it. A bare instant read of
        // `queue_metrics_workers_count` is a gauge — the size of the worker
        // fleet right now — and treating it as a counter multiplied it by an
        // hour's worth of arrivals.
        if ($query->fn === MetricFn::None) {
            return $query->agg === MetricAgg::Count;
        }

        return true;
    }

    /**
     * The selector's own filters, as labels — they shape the value without
     * appearing in the series' labels, exactly as a real backend behaves.
     *
     * A card reads one counter three ways: the total, then the same counter
     * filtered to `http_response_status_code=~"5.."`, then filtered to
     * `error_type!=""`. Dropping the filters answered all three with the same
     * number, and the outgoing HTTP card reported 13.4K requests, 13.4K server
     * errors and 13.4K connection failures — three identical stats side by
     * side, which is the most obviously wrong a fixture can look.
     *
     * @return array<string, string>
     */
    private function filterLabels(MetricQuery $query): array
    {
        $labels = [];

        foreach ($query->rawMatchers as $fragment) {
            foreach (explode(',', $fragment) as $part) {
                if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.]*)\s*(=~|!~|!=|=)\s*"(.*)"\s*$/', $part, $m) !== 1) {
                    continue;
                }

                [, $label, $op, $value] = $m;

                // The metric's own name is not a dimension of it.
                if ($label === '__name__') {
                    continue;
                }

                if ($value === '') {
                    // `error_type!=""` selects the calls that HAVE an error;
                    // `http_response_status_code=""` selects the ones with no
                    // status at all, which is an absence and shapes nothing.
                    if ($op === '!=' || $op === '!~') {
                        $labels[$label] = 'failed';
                    }

                    continue;
                }

                $labels[$label] = $value;
            }
        }

        return $labels;
    }

    /**
     * How many seconds of counting the query is asking for.
     *
     * `rate(m[5m])` is per second, `increase(m[1h])` is an hour's worth, and a
     * bare read of a counter is cumulative. A card puts two of those side by
     * side — the chart as a per-minute rate, the stat as the period total —
     * so a fixture that answers the same number to both contradicts itself on
     * screen.
     */
    private function countScale(MetricQuery $query): float
    {
        return match ($query->fn) {
            MetricFn::Rate => 1.0,
            MetricFn::Increase, MetricFn::CounterIncrease => $this->windowSeconds($query->window),
            // A bare counter selector is a running total; an hour of it is a
            // plausible thing to have accumulated.
            MetricFn::None => 3_600.0,
        };
    }

    /**
     * The metric a verbatim PromQL expression reads.
     *
     * The system and host cards are config-driven and hand-write their
     * PromQL, so their queries arrive as {@see MetricQuery::raw()} with no
     * name and no `by`. Treating the whole expression as the metric name made
     * every one of them a single unlabelled series shaped from a string, so
     * the memory chart — whose own subtitle promises used, cached, buffered
     * and free — drew one line.
     */
    private function rawName(string $promql): string
    {
        // The `by (…)` and `without (…)` clauses first, or the metric is
        // whatever label the expression happens to group by: the memory chart
        // was shaped from the name "system_memory_state", which is a
        // dimension of the metric and not a metric at all.
        $promql = (string) preg_replace('/\b(by|without)\s*\([^)]*\)/i', ' ', $promql);

        if (preg_match('/__name__\s*=~?\s*"([^"]*)"/', $promql, $m) === 1) {
            $literal = (string) preg_replace('/[(\[{|^$*+?.].*$/', '', $m[1]);

            if ($literal !== '') {
                return $literal;
            }
        }

        // A selector: the identifier immediately before its label braces.
        if (preg_match('/([a-z_][a-z0-9_:]*)\s*\{/i', $promql, $m) === 1) {
            return $m[1];
        }

        foreach (self::identifiers($promql) as $identifier) {
            return $identifier;
        }

        return $promql;
    }

    /**
     * The labels a verbatim PromQL expression groups by — the OUTERMOST
     * `by (…)`, which is the one that shapes the result.
     *
     * @return list<string>
     */
    private function rawBy(string $promql): array
    {
        if (preg_match('/\bby\s*\(([^)]*)\)/i', $promql, $m) !== 1) {
            return [];
        }

        $labels = array_values(array_filter(array_map(
            static fn (string $label): string => trim($label),
            explode(',', $m[1]),
        ), static fn (string $label): bool => $label !== ''));

        return $labels;
    }

    /**
     * Identifiers in an expression that are not PromQL's own vocabulary.
     *
     * @return list<string>
     */
    private static function identifiers(string $promql): array
    {
        $reserved = [
            'sum', 'avg', 'min', 'max', 'count', 'count_values', 'stddev', 'stdvar',
            'topk', 'bottomk', 'quantile', 'group', 'by', 'without', 'on', 'ignoring',
            'group_left', 'group_right', 'offset', 'bool', 'and', 'or', 'unless',
            'rate', 'irate', 'increase', 'delta', 'idelta', 'deriv', 'predict_linear',
            'histogram_quantile', 'clamp', 'clamp_min', 'clamp_max', 'label_replace',
            'label_join', 'abs', 'ceil', 'floor', 'round', 'time', 'timestamp',
            'absent', 'absent_over_time', 'changes', 'resets', 'scalar', 'vector',
        ];

        preg_match_all('/[a-z_][a-z0-9_:]*/i', $promql, $matches);

        return array_values(array_filter(
            $matches[0],
            static fn (string $token): bool => ! in_array(strtolower($token), $reserved, true),
        ));
    }

    /** A PromQL duration (`30s`, `5m`, `1h`, `7d`) in seconds. */
    private function windowSeconds(string $window): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m|h|d|w)$/', trim($window), $m) !== 1) {
            return 300.0;
        }

        return (float) $m[1] * match ($m[2]) {
            'ms' => 0.001,
            's' => 1.0,
            'm' => 60.0,
            'h' => 3_600.0,
            'd' => 86_400.0,
            default => 604_800.0,
        };
    }

    /**
     * What the query is actually asking about.
     *
     * A selector may carry no name at all and name the metric in a `__name__`
     * matcher instead — which is not a trick, it is how Prometheus models a
     * metric name. The host cards do exactly that to accept either spelling
     * of a semconv rename (`system_cpu_utilization(_ratio)?`), and reading
     * only the empty `name` shaped those series from nothing: the hosts table
     * reported 74,631% CPU.
     */
    private function metricName(MetricQuery $query): string
    {
        if ($query->name !== '') {
            return $query->name;
        }

        if ($query->raw !== null) {
            return $this->rawName($query->raw);
        }

        foreach ($query->rawMatchers as $fragment) {
            if (preg_match('/__name__\s*=~?\s*"([^"]*)"/', $fragment, $m) === 1) {
                // The literal head of the pattern: `system_cpu_utilization(_ratio)?`
                // is that metric, whichever of the two spellings it matches.
                $literal = (string) preg_replace('/[(\[{|^$*+?.].*$/', '', $m[1]);

                if ($literal !== '') {
                    return $literal;
                }
            }
        }

        return (string) $query->raw;
    }

    /**
     * One label set per value of whatever the query groups by, so a legend
     * reads like a legend. An ungrouped query gets a single series.
     *
     * @return list<array<string, string>>
     */
    private function seriesLabels(MetricQuery $query): array
    {
        $by = $query->by !== [] || $query->raw === null ? $query->by : $this->rawBy($query->raw);

        if ($by === []) {
            return [[]];
        }

        $sets = [[]];

        // Three labels, not two. The routes table groups by route, method AND
        // status code, and expanding only the first two meant no series ever
        // carried a status — so every request bucketed as 1/2/3XX and the
        // table's 4XX and 5XX columns were zero on a dashboard whose own
        // subtitle says "most server errors first".
        foreach (array_slice($by, 0, 3) as $label) {
            $values = array_slice($this->data->labelValues($label, strtolower($this->metricName($query))), 0, 5);
            $next = [];

            foreach ($sets as $set) {
                foreach ($values as $value) {
                    $next[] = $set + [$label => $value];
                }
            }

            $sets = $next;
        }

        // Enough for route × method × status without truncating whole routes
        // out of the table; a card that groups by one label still gets five.
        return array_slice($sets, 0, 40);
    }
}
