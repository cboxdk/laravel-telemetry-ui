<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\Telemetry\Instrumentation\QueryInstrumentation;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Database query performance, New-Relic-"Databases"-style: DB spans sampled from
 * traces and aggregated by the (already parameterised) query text, so you see
 * which query costs the most DB time *in total* — not just the single slowest
 * instance. Columns: calls, avg, p95, max and total time (+ share of DB time).
 *
 * Laravel emits `db.query.text` already parameterised (`?` placeholders, no
 * literals — {@see QueryInstrumentation}), so
 * the raw text is a stable fingerprint with no normalisation needed.
 *
 * Aggregation is a bounded read-side fold over a trace sample (like
 * {@see UnifiedErrors}); it's representative, not exact. A ClickHouse store can
 * do the same GROUP BY exactly over every span — that's where it beats Tempo.
 */
final class QueryPerformance extends Card
{
    private const SEARCH_LIMIT = 200;

    /** Sparkline resolution across the period. */
    private const BUCKETS = 24;

    #[Url(as: 'q_min')]
    public int $minMs = 0;

    #[Url(as: 'q_sort')]
    public string $sort = 'total';

    #[Url(as: 'q_search')]
    public string $search = '';

    /** @var list<int> */
    public array $thresholds = [0, 10, 50, 100, 250, 500];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $fromNano = $start->getTimestamp() * 1_000_000_000;
        $span = max(1, $end->getTimestamp() * 1_000_000_000 - $fromNano);

        $rows = [];
        $error = null;
        $totalDbMs = 0.0;

        try {
            $conditions = [TraceCondition::nil('span.db.query.text')];

            if ($this->minMs > 0) {
                $conditions[] = TraceCondition::token('duration', TraceOp::Gt, $this->minMs.'ms');
            }

            $query = $this->traceQuery(...$conditions)->select('span.db.query.text', 'span.db.system.name');

            /** @var array<string, array{query: string, system: string, calls: int, totalMs: float, maxMs: float, durations: list<float>, buckets: array<int, int>}> $groups */
            $groups = [];

            foreach ($this->traces()->search($query, $start, $end, limit: self::SEARCH_LIMIT) as $summary) {
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
                    $totalDbMs += $ms;
                }
            }

            $rows = $this->rank($groups, $totalDbMs);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.query-performance';

        return view($view, [
            'rows' => $rows,
            'error' => $error,
            'totalDbMs' => $totalDbMs,
        ]);
    }

    /**
     * Turn the folded groups into sorted, capped display rows.
     *
     * @param  array<string, array{query: string, system: string, calls: int, totalMs: float, maxMs: float, durations: list<float>, buckets: array<int, int>}>  $groups
     * @return list<array{query: string, system: string, calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float, share: float, spark: list<int>}>
     */
    private function rank(array $groups, float $totalDbMs): array
    {
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
                'share' => $totalDbMs > 0.0 ? $group['totalMs'] / $totalDbMs : 0.0,
                'spark' => array_values($group['buckets']),
            ];
        }

        $key = match ($this->sort) {
            'avg' => 'avgMs',
            'p95' => 'p95Ms',
            'max' => 'maxMs',
            'calls' => 'calls',
            default => 'totalMs',
        };

        usort($rows, static fn (array $a, array $b): int => $b[$key] <=> $a[$key]);

        return array_slice($rows, 0, 100);
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

    /**
     * Drill: the Traces page filtered to this exact query text.
     */
    public function tracesUrl(string $query): string
    {
        return $this->pageUrl('traces', ['q' => '{ span.db.query.text = "'.addcslashes($query, '"\\').'" }']);
    }
}
