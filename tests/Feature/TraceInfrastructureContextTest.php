<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\MetricSummary;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Opening a trace should say what the machine and the things it called
 * looked like — not only what the app said about itself. That is the whole
 * argument for reading the exporters someone already installed.
 */
function infraTrace(): Trace
{
    $now = time() * 1_000_000_000;

    return new Trace('t1', [
        new Span('a1', null, 'GET /checkout', 'checkout', SpanKind::Server, $now, $now + 900_000_000, ['http.route' => '/checkout'], false),
        new Span('a2', 'a1', 'cache.get', 'checkout', SpanKind::Client, $now, $now + 5_000_000, [
            'db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379',
        ], true),
    ], ['checkout' => ['host.name' => 'web-3']]);
}

/**
 * @param  list<string>  $metricNames
 * @param  list<string>  $instances
 */
function fakeInfraStack(array $metricNames, array $instances, float $value = 0.97): void
{
    $range = ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [[
        'metric' => [],
        'values' => [[time() - 60, (string) $value], [time(), (string) $value]],
    ]]]];

    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => $metricNames]),
        'prometheus.test:9090/api/v1/label/*/values*' => Http::response(['status' => 'success', 'data' => $instances]),
        'prometheus.test:9090/api/v1/query_range*' => Http::response($range),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [time(), (string) $value]]]]]),
        'tempo.test:3200/api/v2/search/tag/*' => Http::response(['tagValues' => [
            ['type' => 'string', 'value' => 'web-3'],
            ['type' => 'string', 'value' => 'cache-1'],
        ]]),
        'tempo.test:3200/*' => Http::response(['tagValues' => []]),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config()->set('telemetry-ui.context.signals', []);
});

it('adds what the host exporter knows to a trace', function (): void {
    fakeInfraStack(['node_load1', 'node_cpu_seconds_total'], ['web-3:9100']);
    app(Discoverer::class)->discover();

    $summaries = app(SignalContext::class)->forTrace(infraTrace());
    $labels = array_map(static fn (MetricSummary $s): string => $s->label, $summaries);

    expect($labels)->toContain('Load (1m)')
        ->and($summaries[0]->group)->toBe('host');
});

it('adds what the cache the request actually called knows', function (): void {
    fakeInfraStack(['redis_up', 'redis_memory_used_bytes'], ['redis://cache-1:6379']);
    app(Discoverer::class)->discover();

    $summaries = app(SignalContext::class)->forTrace(infraTrace());
    $groups = array_unique(array_map(static fn (MetricSummary $s): string => $s->group, $summaries));

    expect($groups)->toContain('cache')
        ->and(array_map(static fn (MetricSummary $s): string => $s->label, $summaries))
        ->toContain('Memory used');
});

it('asks only for the signals discovery saw return data', function (): void {
    fakeInfraStack(['node_load1'], ['web-3:9100'], value: 0.0);
    // Everything read as zero, so discovery recorded every signal absent.
    app(Discoverer::class)->discover();

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => []]),
        'tempo.test:3200/*' => Http::response(['tagValues' => []]),
    ]);

    expect(app(SignalContext::class)->forTrace(infraTrace()))->toBe([]);
});

it('says nothing when no exporter describes anything in the trace', function (): void {
    fakeInfraStack(['node_load1'], ['10.99.0.1:9100']);
    app(Discoverer::class)->discover();

    expect(app(SignalContext::class)->forTrace(infraTrace()))->toBe([]);
});
