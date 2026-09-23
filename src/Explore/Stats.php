<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

/**
 * Small, dependency-free aggregation helpers for Explore rows: percentiles,
 * group-by folds, time series and the time × latency heatmap.
 */
final class Stats
{
    /** Latency band upper bounds in ms for the heatmap (last band is open). */
    public const BANDS = [10, 25, 50, 100, 250, 1000, 5000];

    /**
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil($q * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }

    /**
     * Headline numbers over request-shaped rows.
     *
     * @param  list<array{durationMs: float, error: bool, traceId: string, ...}>  $rows
     * @return array{count: int, errors: int, errorRate: float, avg: float|null, p50: float|null, p95: float|null, p99: float|null, traces: int, perMinute: float}
     */
    public static function red(array $rows, int $rangeSeconds): array
    {
        $durations = array_map(static fn (array $row): float => $row['durationMs'], $rows);
        $errors = count(array_filter($rows, static fn (array $row): bool => $row['error']));
        $count = count($rows);

        return [
            'count' => $count,
            'errors' => $errors,
            'errorRate' => $count > 0 ? $errors / $count : 0.0,
            'avg' => $count > 0 ? array_sum($durations) / $count : null,
            'p50' => self::percentile($durations, 0.50),
            'p95' => self::percentile($durations, 0.95),
            'p99' => self::percentile($durations, 0.99),
            'traces' => count(array_unique(array_map(static fn (array $row): string => $row['traceId'], $rows))),
            'perMinute' => $rangeSeconds > 0 ? $count / ($rangeSeconds / 60) : 0.0,
        ];
    }

    /**
     * Group rows by one attribute: count, errors, error rate, avg and p95 per
     * value, most frequent first. Rows without the attribute fold into "(none)".
     *
     * @param  list<array{durationMs: float, error: bool, attributes: array<string, string>, ...}>  $rows
     * @return list<array{value: string, count: int, errors: int, errorRate: float, avg: float, p95: float|null, share: float}>
     */
    public static function groupBy(array $rows, string $key, int $limit = 50): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $value = $row['attributes'][$key] ?? '';
            $value = $value === '' ? '(none)' : $value;

            $groups[$value] ??= ['durations' => [], 'errors' => 0];
            $groups[$value]['durations'][] = $row['durationMs'];
            $groups[$value]['errors'] += $row['error'] ? 1 : 0;
        }

        $total = max(1, count($rows));
        $out = [];

        foreach ($groups as $value => $group) {
            $count = count($group['durations']);
            $out[] = [
                'value' => (string) $value,
                'count' => $count,
                'errors' => $group['errors'],
                'errorRate' => $group['errors'] / $count,
                'avg' => array_sum($group['durations']) / $count,
                'p95' => self::percentile($group['durations'], 0.95),
                'share' => $count / $total,
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Top values of one attribute with counts (a facet), most frequent first.
     *
     * @param  list<array<string, string>>  $bags
     * @return list<array{value: string, count: int}>
     */
    public static function topValues(array $bags, string $key, int $limit = 8): array
    {
        $counts = [];

        foreach ($bags as $bag) {
            if (! isset($bag[$key]) || $bag[$key] === '') {
                continue;
            }

            $counts[$bag[$key]] = ($counts[$bag[$key]] ?? 0) + 1;
        }

        arsort($counts);

        $out = [];

        foreach (array_slice($counts, 0, $limit, true) as $value => $count) {
            $out[] = ['value' => (string) $value, 'count' => $count];
        }

        return $out;
    }

    /**
     * Count and error series over N equal time buckets, for the Explore
     * distribution chart and entity trends: `[[ms, value], …]` per series.
     *
     * @param  list<array{startMs: int, durationMs: float, error: bool, ...}>  $rows
     * @return array{count: list<array{int, int}>, errors: list<array{int, int}>, p95: list<array{int, float|null}>, bucketMs: int}
     */
    public static function series(array $rows, int $startMs, int $endMs, int $buckets = 60): array
    {
        $width = max(1, intdiv(max(1, $endMs - $startMs), $buckets));
        $count = array_fill(0, $buckets, 0);
        $errors = array_fill(0, $buckets, 0);
        $durations = array_fill(0, $buckets, []);

        foreach ($rows as $row) {
            $i = intdiv($row['startMs'] - $startMs, $width);

            if ($i < 0 || $i >= $buckets) {
                continue;
            }

            $count[$i]++;
            $errors[$i] += $row['error'] ? 1 : 0;
            $durations[$i][] = $row['durationMs'];
        }

        $out = ['count' => [], 'errors' => [], 'p95' => [], 'bucketMs' => $width];

        for ($i = 0; $i < $buckets; $i++) {
            $t = $startMs + $i * $width;
            $out['count'][] = [$t, $count[$i]];
            $out['errors'][] = [$t, $errors[$i]];
            $out['p95'][] = [$t, self::percentile($durations[$i], 0.95)];
        }

        return $out;
    }

    /**
     * Time × latency heatmap: x = time buckets, y = latency bands.
     *
     * @param  list<array{startMs: int, durationMs: float, ...}>  $rows
     *                                                                   `width` (ms per column) and `bands` (lower/upper ms per row, null =
     *                                                                   open) let a clicked cell become a time window + duration filter.
     * @return array{xs: list<int>, ys: list<string>, cells: list<array{int, int, int}>, max: int, width: int, bands: list<array{int, int|null}>}
     */
    public static function heatmap(array $rows, int $startMs, int $endMs, int $buckets = 40): array
    {
        $width = max(1, intdiv(max(1, $endMs - $startMs), $buckets));
        $grid = [];

        foreach ($rows as $row) {
            $x = intdiv($row['startMs'] - $startMs, $width);

            if ($x < 0 || $x >= $buckets) {
                continue;
            }

            $y = count(self::BANDS);

            foreach (self::BANDS as $i => $bound) {
                if ($row['durationMs'] < $bound) {
                    $y = $i;
                    break;
                }
            }

            $grid[$x][$y] = ($grid[$x][$y] ?? 0) + 1;
        }

        $cells = [];
        $max = 0;

        foreach ($grid as $x => $column) {
            foreach ($column as $y => $n) {
                $cells[] = [$x, $y, $n];
                $max = max($max, $n);
            }
        }

        $ys = [];
        $previous = 0;

        foreach (self::BANDS as $bound) {
            $ys[] = $previous === 0 ? '<'.self::band($bound) : self::band($previous).'–'.self::band($bound);
            $previous = $bound;
        }

        $ys[] = '>'.self::band($previous);

        return [
            'xs' => array_map(static fn (int $i): int => $startMs + $i * $width, range(0, $buckets - 1)),
            'ys' => $ys,
            'cells' => $cells,
            'max' => $max,
            'width' => $width,
            'bands' => array_map(
                static fn (int $i): array => [$i === 0 ? 0 : self::BANDS[$i - 1], self::BANDS[$i] ?? null],
                range(0, count(self::BANDS)),
            ),
        ];
    }

    private static function band(int $ms): string
    {
        return $ms >= 1000 ? ($ms / 1000).'s' : $ms.'ms';
    }
}
