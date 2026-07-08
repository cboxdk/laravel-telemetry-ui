<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\QueryPerformance;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Cbox\TelemetryUi\Queries\Results\Trace;
use DateTimeInterface;
use Livewire\Livewire;

/**
 * A traces backend that aggregates server-side — the ClickHouse-store capability,
 * stubbed so we can prove QueryPerformance takes the EXACT path when available.
 */
function aggregatingTracesStub(): TracesSource&AggregatesSpans
{
    return new class implements AggregatesSpans, TracesSource
    {
        public array $received = [];

        public function search(TraceQuery $query, DateTimeInterface $start, DateTimeInterface $end, int $limit = 20): array
        {
            throw new RuntimeException('exact path must not sample via search()');
        }

        public function trace(string $traceId): Trace
        {
            return new Trace($traceId, []);
        }

        public function tagValues(string $tag, ?TraceQuery $filter = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null, int $limit = 0): array
        {
            return [];
        }

        public function aggregateSpans(SpanAggregation $aggregation, DateTimeInterface $start, DateTimeInterface $end): array
        {
            return [
                new SpanBucket('update "users" set "seen_at" = ?', count: 48000, avgMs: 1.0, p95Ms: 3.0, maxMs: 90.0, totalMs: 48000.0, attributes: ['db.system.name' => 'pgsql']),
                new SpanBucket('select * from "orders" where "id" = ?', count: 12000, avgMs: 8.0, p95Ms: 22.0, maxMs: 640.0, totalMs: 96000.0, attributes: ['db.system.name' => 'pgsql']),
            ];
        }
    };
}

it('uses exact server-side aggregation when the traces backend supports it', function (): void {
    $stub = aggregatingTracesStub();
    app(ConnectionManager::class)->extend('agg-stub', static fn (array $config): TracesSource => $stub);
    config()->set('telemetry-ui.connections.traces', ['driver' => 'agg-stub']);

    Livewire::test(QueryPerformance::class)
        ->assertOk()
        ->assertSee('Exact aggregation over every matching span')
        ->assertSee('select * from "orders" where "id" = ?')
        ->assertSee('update "users" set "seen_at" = ?')
        // Ranked by total time: orders (96s) above the update (48s), even though
        // the update runs 4× as often — the New Relic insight.
        ->assertSeeInOrder(['select * from "orders" where "id" = ?', 'update "users" set "seen_at" = ?']);
});
