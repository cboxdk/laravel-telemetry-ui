<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\SpanSort;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Support\Format;
use DateTimeInterface;

/**
 * Database query performance, New-Relic-"Databases"-style: DB spans aggregated
 * by the (already parameterised) query text, so you see which query costs the
 * most DB time *in total* — not just the single slowest instance. Columns:
 * calls, avg, p95, max and total time (+ share of DB time).
 *
 * Laravel emits `db.query.text` already parameterised (`?` placeholders, no
 * literals — {@see QueryInstrumentation}), so the raw text is a stable
 * fingerprint with no normalisation needed.
 *
 * When the traces backend can aggregate server-side ({@see AggregatesSpans} — a
 * ClickHouse store) the numbers are EXACT over every span. Otherwise it folds a
 * bounded trace sample read-side (like {@see UnifiedErrors}) — representative,
 * not exact. The card renders the same either way; a badge says which.
 */
final class QueryPerformance extends Panel
{
    private const SEARCH_LIMIT = 200;

    /** Sparkline resolution across the period (sampled path only). */
    private const BUCKETS = 24;

    #[Param('q_min')]
    public int $minMs = 0;

    #[Param('q_sort')]
    public string $sort = 'total';

    #[Param('q_search')]
    public string $search = '';

    public static function span(): int
    {
        return 2;
    }

    /** @var list<int> */
    public array $thresholds = [0, 10, 50, 100, 250, 500];

    public function data(): array
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;
        $exact = false;

        try {
            $source = $this->traces();
            $where = $this->traceQuery(...$this->conditions());

            if ($source instanceof AggregatesSpans) {
                $exact = true;
                $rows = $this->exactRows($source, $where, $start, $end);
            } else {
                $rows = $this->sampledRows($source, $where, $start, $end);
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static function (array $row): array {
            // Drill: the per-statement detail page.
            $link = Ui::entity('query', $row['query']);

            return [
                'query' => Ui::cell($row['query'], ['mono' => true, 'link' => $link]),
                'system' => Ui::cell($row['system']),
                'calls' => Ui::cell(Format::count($row['calls']), ['raw' => $row['calls']]),
                'avg' => Ui::cell(Format::ms($row['avgMs']), ['raw' => $row['avgMs']]),
                'p95' => Ui::cell(Format::ms($row['p95Ms']), ['raw' => $row['p95Ms']]),
                'max' => Ui::cell(Format::ms($row['maxMs']), ['raw' => $row['maxMs'], 'tone' => $row['maxMs'] >= 500 ? 'warn' : null]),
                'total' => Ui::cell(Format::ms($row['totalMs']), ['raw' => $row['totalMs']]),
                'share' => Ui::cell(Format::percent($row['share']), ['raw' => $row['share'], 'bar' => $row['share']]),
                'trend' => Ui::cell(null, ['spark' => array_map(floatval(...), $row['spark'])]),
                '_link' => $link,
            ];
        }, $this->finalize($rows));

        return Ui::table('Query performance', [
            Ui::col('query', 'Query'),
            Ui::col('system', 'DB'),
            Ui::num('calls', 'Calls'),
            Ui::num('avg', 'Avg'),
            Ui::num('p95', 'p95'),
            Ui::num('max', 'Max'),
            Ui::num('total', 'Total'),
            Ui::num('share', 'Share'),
            Ui::col('trend', 'Trend'),
        ], $table, array_filter([
            'subtitle' => 'DB queries aggregated by statement — ranked by the DB time they consume in total, not just the single slowest run',
            'error' => $error,
            'empty' => 'No database queries in this period.',
            'exact' => $exact,
            'note' => ($exact
                ? 'Exact aggregation over every matching span (ClickHouse store).'
                : 'Sampled from the most recent matching traces — representative, not exact (a ClickHouse store aggregates every span).')
                .' Ranked by total DB time consumed; query text is parameterised and redacted at emit time.',
            'controls' => [
                Ui::search('q_search', 'Filter', $this->search, 'Filter queries…'),
                Ui::select('q_min', 'Slower than', (string) $this->minMs, array_map(
                    static fn (int $t): array => ['value' => (string) $t, 'label' => $t === 0 ? 'All' : $t.'ms'],
                    $this->thresholds,
                )),
                Ui::select('q_sort', 'Rank by', $this->sort, [
                    ['value' => 'total', 'label' => 'Total time'],
                    ['value' => 'avg', 'label' => 'Avg'],
                    ['value' => 'p95', 'label' => 'p95'],
                    ['value' => 'max', 'label' => 'Max'],
                    ['value' => 'calls', 'label' => 'Calls'],
                ]),
            ],
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * The scope conditions every path filters by: db spans (query text present),
     * optionally slower than the threshold.
     *
     * @return list<TraceCondition>
     */
    private function conditions(): array
    {
        $conditions = [TraceCondition::nil('span.db.query.text')];

        if ($this->minMs > 0) {
            $conditions[] = TraceCondition::token('duration', TraceOp::Gt, $this->minMs.'ms');
        }

        return $conditions;
    }

    /**
     * Exact server-side aggregation over every matching span (ClickHouse store).
     *
     * @return list<array{query: string, system: string, calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float, spark: list<int>}>
     */
    private function exactRows(AggregatesSpans $source, TraceQuery $where, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $aggregation = new SpanAggregation(
            where: $where,
            groupBy: 'span.db.query.text',
            carry: ['span.db.system.name'],
            limit: 100,
            sort: $this->sortEnum(),
        );

        $rows = [];

        foreach ($source->aggregateSpans($aggregation, $start, $end) as $bucket) {
            if ($this->search !== '' && stripos($bucket->key, $this->search) === false) {
                continue;
            }

            $rows[] = [
                'query' => $bucket->key,
                'system' => $bucket->attributes['db.system.name'] ?? '',
                'calls' => $bucket->count,
                'avgMs' => $bucket->avgMs,
                'p95Ms' => $bucket->p95Ms,
                'maxMs' => $bucket->maxMs,
                'totalMs' => $bucket->totalMs,
                'spark' => [],
            ];
        }

        return $rows;
    }

    /**
     * Read-side fold over a bounded trace sample (Tempo / any TracesSource).
     *
     * @return list<array{query: string, system: string, calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float, spark: list<int>}>
     */
    private function sampledRows(TracesSource $source, TraceQuery $where, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $fromNano = $start->getTimestamp() * 1_000_000_000;
        $span = max(1, $end->getTimestamp() * 1_000_000_000 - $fromNano);

        $query = $where->select('span.db.query.text', 'span.db.system.name');

        /** @var array<string, array{query: string, system: string, calls: int, totalMs: float, maxMs: float, durations: list<float>, buckets: array<int, int>}> $groups */
        $groups = [];

        foreach ($source->search($query, $start, $end, limit: self::SEARCH_LIMIT) as $summary) {
            foreach ($summary->matchedSpans as $matched) {
                $text = $matched->attributes['db.query.text'] ?? null;

                if (! is_string($text) || $text === '') {
                    continue;
                }

                $ms = $matched->durationMs;
                $group = $groups[$text] ?? [
                    'query' => $text,
                    'system' => is_string($matched->attributes['db.system.name'] ?? null) ? $matched->attributes['db.system.name'] : '',
                    'calls' => 0, 'totalMs' => 0.0, 'maxMs' => 0.0, 'durations' => [],
                    'buckets' => array_fill(0, self::BUCKETS, 0),
                ];

                $group['calls']++;
                $group['totalMs'] += $ms;
                $group['maxMs'] = max($group['maxMs'], $ms);
                $group['durations'][] = $ms;
                $bucket = min(self::BUCKETS - 1, (int) (($matched->startNano - $fromNano) / $span * self::BUCKETS));
                $group['buckets'][max(0, $bucket)]++;

                $groups[$text] = $group;
            }
        }

        $rows = [];

        foreach ($groups as $group) {
            if ($this->search !== '' && stripos($group['query'], $this->search) === false) {
                continue;
            }

            $rows[] = [
                'query' => $group['query'],
                'system' => $group['system'],
                'calls' => $group['calls'],
                'avgMs' => $group['calls'] > 0 ? $group['totalMs'] / $group['calls'] : 0.0,
                'p95Ms' => self::percentile($group['durations'], 0.95),
                'maxMs' => $group['maxMs'],
                'totalMs' => $group['totalMs'],
                'spark' => array_values($group['buckets']),
            ];
        }

        return $rows;
    }

    /**
     * Sort, cap, and add each row's share of the (shown) DB time.
     *
     * @param  list<array{query: string, system: string, calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float, spark: list<int>}>  $rows
     * @return list<array{query: string, system: string, calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float, share: float, spark: list<int>}>
     */
    private function finalize(array $rows): array
    {
        $key = match ($this->sort) {
            'avg' => 'avgMs',
            'p95' => 'p95Ms',
            'max' => 'maxMs',
            'calls' => 'calls',
            default => 'totalMs',
        };

        usort($rows, static fn (array $a, array $b): int => $b[$key] <=> $a[$key]);
        $rows = array_slice($rows, 0, 100);

        $grandTotal = array_sum(array_column($rows, 'totalMs'));

        return array_map(static function (array $row) use ($grandTotal): array {
            $row['share'] = $grandTotal > 0.0 ? $row['totalMs'] / $grandTotal : 0.0;

            return $row;
        }, $rows);
    }

    private function sortEnum(): SpanSort
    {
        return SpanSort::tryFrom($this->sort) ?? SpanSort::Total;
    }

    /**
     * Nearest-rank percentile over an unsorted sample.
     *
     * @param  list<float>  $values
     */
    private static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil($p * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }
}
