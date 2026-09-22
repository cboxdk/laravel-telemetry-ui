<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Builtin\QueryPerformance;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\Format;

/**
 * One database statement in depth (drilled from {@see QueryPerformance}):
 * its call volume and latency, a trend, the slowest example traces, and which
 * routes/jobs run it. Sampled from the traces carrying this exact (parameterised)
 * `db.query.text`.
 */
final class QueryDetail extends Panel
{
    private const SEARCH_LIMIT = 100;

    private const BUCKETS = 32;

    public static function span(): int
    {
        return 2;
    }

    #[Param('dbq')]
    public string $dbq = '';

    public function data(): array
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

        $title = 'Query detail';
        $meta = array_filter([
            'subtitle' => 'One statement in depth — volume, latency, and who runs it',
            'back' => Ui::page('queries'),
            'backLabel' => '← Queries',
            'badges' => $system !== '' ? [$system] : null,
        ], static fn ($v): bool => $v !== null);

        if ($error !== null) {
            return Ui::composite($title, [], [...$meta, 'error' => $error]);
        }

        if ($this->dbq === '') {
            return Ui::composite($title, [], [...$meta, 'empty' => 'No statement selected.']);
        }

        $sql = Ui::code('Statement', $this->dbq, 'sql');

        if ($stats === null || $stats['calls'] === 0) {
            return Ui::composite($title, [$sql], [...$meta, 'empty' => 'No traces carrying this statement in the period.']);
        }

        $examplesTable = Ui::table('Slowest example traces', [
            Ui::col('origin', 'Origin'),
            Ui::num('duration', 'Duration'),
            Ui::num('when', 'When'),
        ], array_map(static fn (array $ex): array => [
            'origin' => Ui::cell($ex['origin'], ['link' => Ui::trace($ex['traceId'])]),
            'duration' => Ui::cell(Format::ms($ex['durationMs']), ['raw' => $ex['durationMs'], 'tone' => $ex['durationMs'] >= 500 ? 'warn' : null]),
            'when' => Ui::cell($ex['at']->format('H:i:s'), ['raw' => $ex['at']->getTimestamp(), 'mono' => true]),
            '_link' => Ui::trace($ex['traceId']),
        ], $examples), ['empty' => 'No traces.']);

        $callersTable = Ui::table('Called by', [
            Ui::col('origin', 'Route / job'),
            Ui::num('calls', 'Calls'),
            Ui::num('total', 'Total'),
        ], array_map(static fn (array $caller): array => [
            'origin' => Ui::cell($caller['origin']),
            'calls' => Ui::cell(Format::count($caller['calls']), ['raw' => $caller['calls']]),
            'total' => Ui::cell(Format::ms($caller['totalMs']), ['raw' => $caller['totalMs']]),
        ], $callers), ['empty' => 'No callers.']);

        return Ui::composite($title, [
            $sql,
            Ui::stats('', [
                $this->stat('Calls (sample)', Format::count($stats['calls'])),
                // The latency trend (avg per bucket across the period) rides on the Avg tile.
                ['label' => 'Avg', 'value' => Format::ms($stats['avgMs']), 'tone' => null, 'points' => $trend, 'sparkColor' => '#8b5cf6'],
                $this->stat('p95', Format::ms($stats['p95Ms'])),
                $this->stat('Max', Format::ms($stats['maxMs']), $stats['maxMs'] >= 500 ? 'warn' : null),
                $this->stat('Total time', Format::ms($stats['totalMs'])),
            ]),
            $examplesTable,
            $callersTable,
        ], [...$meta, 'note' => 'Sampled from traces carrying this statement — a ClickHouse store aggregates every span exactly.']);
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
}
