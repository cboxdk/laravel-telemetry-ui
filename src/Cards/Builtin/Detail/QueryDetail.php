<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\QueryPerformance;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * One database statement in depth (drilled from {@see QueryPerformance}):
 * its call volume and latency, a trend, the slowest example traces, and which
 * routes/jobs run it. Sampled from the traces carrying this exact (parameterised)
 * `db.query.text`.
 */
final class QueryDetail extends Card
{
    private const SEARCH_LIMIT = 100;

    private const BUCKETS = 32;

    #[Url(as: 'dbq')]
    public string $dbq = '';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $stats = null;
        $callers = [];
        $examples = [];
        $trend = [];
        $system = '';
        $error = null;

        if ($this->dbq !== '') {
            try {
                [$stats, $callers, $examples, $trend, $system] = $this->analyse($start->getTimestamp(), $end->getTimestamp());
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.query-detail';

        return view($view, [
            'query' => $this->dbq,
            'system' => $system,
            'stats' => $stats,
            'callers' => $callers,
            'examples' => $examples,
            'trend' => $trend,
            'error' => $error,
            'backUrl' => $this->pageUrl('queries'),
            'min' => $start->getTimestamp() * 1000,
            'max' => $end->getTimestamp() * 1000,
        ]);
    }

    /**
     * @return array{array{calls: int, avgMs: float, p95Ms: float, maxMs: float, totalMs: float}, list<array{origin: string, calls: int, totalMs: float}>, list<array{traceId: string, origin: string, durationMs: float, at: \DateTimeImmutable}>, list<float>, string}
     */
    private function analyse(int $startSec, int $endSec): array
    {
        $query = $this->traceQuery(TraceCondition::eq('span.db.query.text', $this->dbq))
            ->select('span.db.query.text', 'span.db.system.name');

        [$start, $end] = $this->range();
        $results = $this->traces()->search($query, $start, $end, limit: self::SEARCH_LIMIT);

        $calls = 0;
        $totalMs = 0.0;
        $maxMs = 0.0;
        $durations = [];
        $system = '';
        $buckets = array_fill(0, self::BUCKETS, 0.0);
        $bucketCounts = array_fill(0, self::BUCKETS, 0);
        $span = max(1, $endSec - $startSec);

        /** @var array<string, array{origin: string, calls: int, totalMs: float}> $callers */
        $callers = [];
        $examples = [];

        foreach ($results as $summary) {
            $slowest = 0.0;

            foreach ($summary->matchedSpans as $matched) {
                if (($matched->attributes['db.query.text'] ?? null) !== $this->dbq) {
                    continue;
                }

                $ms = $matched->durationMs;
                $calls++;
                $totalMs += $ms;
                $maxMs = max($maxMs, $ms);
                $slowest = max($slowest, $ms);
                $durations[] = $ms;

                if ($system === '' && is_string($matched->attributes['db.system.name'] ?? null)) {
                    $system = $matched->attributes['db.system.name'];
                }

                $bucket = min(self::BUCKETS - 1, max(0, (int) ((intdiv($matched->startNano, 1_000_000_000) - $startSec) / $span * self::BUCKETS)));
                $buckets[$bucket] += $ms;
                $bucketCounts[$bucket]++;
            }

            if ($slowest <= 0.0) {
                continue;
            }

            $origin = $summary->rootTraceName !== '' ? $summary->rootTraceName : '(unknown)';
            $caller = $callers[$origin] ?? ['origin' => $origin, 'calls' => 0, 'totalMs' => 0.0];
            $caller['calls']++;
            $caller['totalMs'] += $slowest;
            $callers[$origin] = $caller;

            $examples[] = ['traceId' => $summary->traceId, 'origin' => $origin, 'durationMs' => $slowest, 'at' => $summary->startedAt];
        }

        $stats = [
            'calls' => $calls,
            'avgMs' => $calls > 0 ? $totalMs / $calls : 0.0,
            'p95Ms' => self::percentile($durations, 0.95),
            'maxMs' => $maxMs,
            'totalMs' => $totalMs,
        ];

        usort($callers, static fn (array $a, array $b): int => $b['totalMs'] <=> $a['totalMs']);
        usort($examples, static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs']);

        // Trend: average latency per bucket (ms) — a sparkline series.
        $trend = [];
        foreach ($buckets as $i => $sum) {
            $trend[] = $bucketCounts[$i] > 0 ? $sum / $bucketCounts[$i] : 0.0;
        }

        return [$stats, array_slice($callers, 0, 10), array_slice($examples, 0, 20), $trend, $system];
    }

    /**
     * @param  list<float>  $values
     */
    private static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        return $values[max(0, min(count($values) - 1, (int) ceil($p * count($values)) - 1))];
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
