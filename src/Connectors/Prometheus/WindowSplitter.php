<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Prometheus;

use Cbox\TelemetryUi\Queries\Ir\MetricAgg;
use Cbox\TelemetryUi\Queries\Ir\MetricFn;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;

/**
 * Answers a window-wide instant query — `increase(x[24h])`, a 24-hour p95 —
 * as a range query of slices, for Mimir.
 *
 * Mimir's query-frontend splits a RANGE query by day (and caches each day),
 * so each part fetches one day's chunks; an INSTANT query is neither split
 * nor cached, and fetches every chunk of the whole window in one go. A day
 * of a busy service's latency histogram — every route × every bucket — is
 * over `max_fetched_chunks_per_query` as an instant query while the same
 * data draws fine as the panel's own chart (measured on Mimir 3.2: the
 * 24h p95 instant query fails at a limit the range chart and the sliced
 * form both pass). And a longer window grows an instant query with it,
 * where the sliced form stays a day at a time: this is what makes 30 days
 * or a year answerable at all.
 *
 * So the same expression runs as `increase(x[slice])` every slice, and the
 * slices are added up here: counters sum, a rate is the summed increase over
 * the window, a quantile sums each bucket's increase and interpolates the
 * way `histogram_quantile` does.
 *
 * Not for plain Prometheus: it evaluates an instant `rate()` one series at
 * a time, while a range query holds every series × step, so slicing costs
 * it more `query.max-samples`, not less (measured on Prometheus 3.15).
 */
final readonly class WindowSplitter
{
    /** Never longer than Mimir's default split interval: a slice is inside one day's part. */
    private const LONGEST_SLICE = 86_400;

    /**
     * @param  int  $splitAfter  windows longer than this many seconds are sliced (0: never)
     * @param  int  $slice  the preferred slice, in seconds
     * @param  int  $maxSlices  past this many slices they grow instead (up to a day each):
     *                          every slice is a point per series in the answer
     */
    public function __construct(
        private int $splitAfter = 0,
        private int $slice = 3_600,
        private int $maxSlices = 168,
    ) {}

    /**
     * How to answer the query in slices, or null when it should run as is:
     * slicing off, a short window, a raw query, or an aggregation that does
     * not add up across slices (avg, max, min, count).
     *
     * @return array{inner: MetricQuery, slice: int, slices: int, window: int}|null
     */
    public function plan(MetricQuery $query): ?array
    {
        if ($this->splitAfter <= 0 || $query->raw !== null) {
            return null;
        }

        $window = self::seconds($query->window);

        if ($window === null || $window <= $this->splitAfter) {
            return null;
        }

        $additive = $query->quantile !== null
            || (in_array($query->fn, [MetricFn::Increase, MetricFn::Rate], true)
                && in_array($query->agg, [MetricAgg::None, MetricAgg::Sum], true));

        if (! $additive) {
            return null;
        }

        // The preferred slice, until there would be more than maxSlices of
        // them; then longer slices — but never longer than a day, so each
        // stays inside one of the frontend's daily parts (a year is 365
        // slices). What the division leaves over, under a second per slice,
        // falls off the far end of the window.
        $length = max(1, $this->slice, (int) ceil($window / max(1, $this->maxSlices)));
        $length = min($length, self::LONGEST_SLICE);
        $slices = (int) ceil($window / $length);
        $slice = intdiv($window, $slices);

        $inner = new MetricQuery(
            name: $query->name,
            matchers: $query->matchers,
            rawMatchers: $query->rawMatchers,
            fn: MetricFn::Increase,
            window: $slice.'s',
            agg: $query->quantile !== null ? MetricAgg::Sum : $query->agg,
            by: $query->quantile !== null ? [...$query->by, 'le'] : $query->by,
        );

        return ['inner' => $inner, 'slice' => $slice, 'slices' => $slices, 'window' => $window];
    }

    /**
     * Add the slices up into the instant vector the query would have given.
     *
     * @param  array{inner: MetricQuery, slice: int, slices: int, window: int}  $plan
     * @param  list<TimeSeries>  $series  the slice-by-slice range result
     * @return list<Sample>
     */
    public function combine(MetricQuery $query, array $plan, array $series, float $at): array
    {
        /** @var array<string, array{labels: array<string, string>, total: float}> $totals */
        $totals = [];

        foreach ($series as $one) {
            $total = 0.0;
            foreach ($one->points as $point) {
                if (is_finite($point->value)) {
                    $total += $point->value;
                }
            }

            $key = self::key($one->labels);
            $totals[$key] ??= ['labels' => $one->labels, 'total' => 0.0];
            $totals[$key]['total'] += $total;
        }

        $scalar = $query->scalar ?? 1.0;

        if ($query->quantile === null) {
            $samples = [];
            foreach ($totals as ['labels' => $labels, 'total' => $total]) {
                $value = $query->fn === MetricFn::Rate ? $total / $plan['window'] : $total;
                $samples[] = new Sample($labels, $at, $value * $scalar);
            }

            return $samples;
        }

        /** @var array<string, array{labels: array<string, string>, buckets: array<string, float>}> $groups */
        $groups = [];

        foreach ($totals as ['labels' => $labels, 'total' => $total]) {
            $le = $labels['le'] ?? null;
            if ($le === null) {
                continue;
            }
            unset($labels['le']);
            $key = self::key($labels);
            $groups[$key] ??= ['labels' => $labels, 'buckets' => []];
            $groups[$key]['buckets'][$le] = ($groups[$key]['buckets'][$le] ?? 0.0) + $total;
        }

        $samples = [];

        foreach ($groups as ['labels' => $labels, 'buckets' => $buckets]) {
            $value = self::histogramQuantile($query->quantile, $buckets);

            // As Prometheus' vector would: no finite answer, no sample.
            if (is_finite($value)) {
                $samples[] = new Sample($labels, $at, $value * $scalar);
            }
        }

        return $samples;
    }

    /**
     * Prometheus' `histogram_quantile` over cumulative bucket counts keyed by
     * their `le` bound: linear interpolation inside the bucket the rank
     * lands in, the second-highest bound when it lands in `+Inf`, NaN
     * without a `+Inf` bucket or without observations.
     *
     * @param  array<string, float>  $buckets
     */
    public static function histogramQuantile(float $q, array $buckets): float
    {
        if ($q < 0) {
            return -INF;
        }

        if ($q > 1) {
            return INF;
        }

        $bounds = [];
        foreach ($buckets as $le => $count) {
            $bound = in_array(strtolower((string) $le), ['+inf', 'inf'], true) ? INF : (is_numeric($le) ? (float) $le : null);
            if ($bound !== null) {
                $bounds[] = [$bound, $count];
            }
        }

        usort($bounds, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        if (count($bounds) < 2 || end($bounds)[0] !== INF) {
            return NAN;
        }

        // Summed slices can dip where a counter reset landed on a bucket
        // edge; cumulative counts never decrease.
        $max = 0.0;
        foreach ($bounds as $i => [$bound, $count]) {
            $max = max($max, $count);
            $bounds[$i][1] = $max;
        }

        $observations = end($bounds)[1];

        if ($observations <= 0) {
            return NAN;
        }

        $rank = $q * $observations;
        $index = 0;
        while ($bounds[$index][1] < $rank) {
            $index++;
        }

        if ($index === count($bounds) - 1) {
            return $bounds[$index - 1][0];
        }

        if ($index === 0 && $bounds[0][0] <= 0) {
            return $bounds[0][0];
        }

        $start = $index === 0 ? 0.0 : $bounds[$index - 1][0];
        $below = $index === 0 ? 0.0 : $bounds[$index - 1][1];
        $inBucket = $bounds[$index][1] - $below;

        if ($inBucket <= 0) {
            return $bounds[$index][0];
        }

        return $start + ($bounds[$index][0] - $start) * (($rank - $below) / $inBucket);
    }

    /**
     * A PromQL duration in seconds: `86400s`, `15m`, `1h30m`, `7d`. Null for
     * anything else (a subquery, a variable).
     */
    public static function seconds(string $window): ?int
    {
        if ($window === '' || preg_match('/^(\d+(ms|s|m|h|d|w|y))+$/', $window) !== 1) {
            return null;
        }

        preg_match_all('/(\d+)(ms|s|m|h|d|w|y)/', $window, $parts, PREG_SET_ORDER);

        $units = ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400, 'w' => 604_800, 'y' => 31_536_000];
        $seconds = 0.0;
        foreach ($parts as $part) {
            $seconds += (int) $part[1] * $units[$part[2]];
        }

        return (int) round($seconds);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function key(array $labels): string
    {
        ksort($labels);

        return (string) json_encode($labels);
    }
}
