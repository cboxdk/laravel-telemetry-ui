<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\MetricSummary;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function rangeResponse(float ...$values): array
{
    $points = [];
    $t = 1735689600;
    foreach ($values as $v) {
        $points[] = [$t, (string) $v];
        $t += 60;
    }

    return [
        'status' => 'success',
        'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => [], 'values' => $points],
        ]],
    ];
}

it('summarizes a context signal for a scope and window', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(0.2, 0.5, 0.9)),
    ]);

    $summaries = app(SignalContext::class)->for(
        ['service_name' => 'cbox-web', 'host_name' => 'web-1'],
        new DateTimeImmutable('@1735689600'),
        new DateTimeImmutable('@1735689780'),
    );

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0])->toBeInstanceOf(MetricSummary::class)
        ->and($summaries[0]->label)->toBe('Host CPU')
        ->and($summaries[0]->current)->toBe(0.9)
        ->and($summaries[0]->max)->toBe(0.9)
        ->and(round($summaries[0]->avg, 3))->toBe(0.533)
        // Baseline is computed too, and 0.9 vs a 0.53 typical is an outlier.
        ->and(round($summaries[0]->baseline, 3))->toBe(0.533)
        ->and($summaries[0]->isOutlier())->toBeTrue();

    // The {scope} token expanded to the label matchers.
    Http::assertSent(fn ($request): bool => str_contains(
        rawurldecode($request->url()),
        'system_cpu_utilization_ratio{service_name="cbox-web",host_name="web-1"}',
    ));
});

it('does not flag a signal sitting at its baseline', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(0.3, 0.3, 0.3)),
    ]);

    $summaries = app(SignalContext::class)->for(
        ['service_name' => 'cbox-web'],
        new DateTimeImmutable('@1735689600'),
        new DateTimeImmutable('@1735689780'),
    );

    expect($summaries[0]->baseline)->toBe(0.3)
        ->and($summaries[0]->isOutlier())->toBeFalse();
});

it('skips a signal whose metric is absent or all-zero — never errors', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Present', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(presentmetric{{scope}})'],
        ['label' => 'Missing', 'group' => 'db', 'unit' => 'number', 'query' => 'avg(missingmetric{{scope}})'],
        ['label' => 'AllZero', 'group' => 'host', 'unit' => 'number', 'query' => 'avg(zerometric{{scope}})'],
    ]);

    Http::fake([
        'prometheus.test:9090/*missingmetric*' => Http::response('down', 502),
        'prometheus.test:9090/*zerometric*' => Http::response(rangeResponse(0.0, 0.0)),
        'prometheus.test:9090/*presentmetric*' => Http::response(rangeResponse(1.0, 2.0)),
    ]);

    $summaries = app(SignalContext::class)->for(
        ['service_name' => 'x'],
        new DateTimeImmutable('@1735689600'),
        new DateTimeImmutable('@1735689780'),
    );

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->label)->toBe('Present');
});

it('derives scope and a padded window from a trace', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);
    config()->set('telemetry-ui.context.window', 600);

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(0.4)),
    ]);

    $span = new Span('a1', null, 'GET /orders', 'cbox-web', SpanKind::Server, 1735689600_000000000, 1735689601_000000000, [], false);
    $trace = new Trace('abc', [$span], ['cbox-web' => ['host.name' => 'web-7']]);

    $summaries = app(SignalContext::class)->forTrace($trace);

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->current)->toBe(0.4);

    Http::assertSent(function ($request): bool {
        $q = rawurldecode($request->url());

        return str_contains($q, 'service_name="cbox-web"')
            && str_contains($q, 'host_name="web-7"')
            // window padded 300s each side of the 1s trace.
            && str_contains($request->url(), 'start=1735689300')
            && str_contains($request->url(), 'end=1735689901');
    });
});

it('fills {host}, {service} and {environment} from the trace, for exporters with their own labels', function (): void {
    config()->set('telemetry-ui.scope.labels.traces.environment', 'resource.deployment.environment');
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Load (1m)', 'group' => 'host', 'unit' => 'number', 'query' => 'max(node_load1{nodename="{host}"})'],
        ['label' => 'DB threads running', 'group' => 'db', 'unit' => 'number', 'query' => 'sum(mysql_global_status_threads_running{environment="{environment}"})'],
    ]);

    Http::fake(['prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(1.0, 2.0, 3.0))]);

    $span = new Span('s1', null, 'GET /geocode', 'geocodio-app', SpanKind::Server, 1735689600_000_000_000, 1735689601_000_000_000, [], false);
    $trace = new Trace('t1', [$span], ['geocodio-app' => ['host.name' => 'api184.example', 'deployment.environment' => 'production']]);

    $summaries = app(SignalContext::class)->forTrace($trace);

    expect(array_map(fn (MetricSummary $s): string => $s->label, $summaries))->toBe(['Load (1m)', 'DB threads running']);
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'node_load1{nodename="api184.example"}'));
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'mysql_global_status_threads_running{environment="production"}'));
});

it('skips a signal that needs a value the trace does not have', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Load (1m)', 'group' => 'host', 'unit' => 'number', 'query' => 'max(node_load1{nodename="{host}"})'],
    ]);

    Http::fake(['prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(1.0, 2.0))]);

    $span = new Span('s1', null, 'GET /geocode', 'geocodio-app', SpanKind::Server, 1735689600_000_000_000, 1735689601_000_000_000, [], false);
    $trace = new Trace('t1', [$span], ['geocodio-app' => []]);

    expect(app(SignalContext::class)->forTrace($trace))->toBe([]);
    Http::assertNothingSent();
});

it('keeps a flat-zero signal that says zero is the answer', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Worker queue', 'group' => 'runtime', 'unit' => 'number', 'keep_zero' => true, 'query' => 'sum(phpfpm_listen_queue{{scope}})'],
        ['label' => 'Hidden', 'group' => 'runtime', 'unit' => 'number', 'query' => 'sum(other{{scope}})'],
    ]);

    Http::fake(['prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(0.0, 0.0, 0.0))]);

    $summaries = app(SignalContext::class)->for(['service_name' => 'cbox-web'], new DateTimeImmutable('@1735689600'), new DateTimeImmutable('@1735689780'));

    expect(array_map(fn (MetricSummary $s): string => $s->label, $summaries))->toBe(['Worker queue']);
});

it('reads a cached baseline back from a store that returns numbers as strings, as Redis does', function (): void {
    // Laravel's Redis and Memcached stores keep a number unserialized, so it
    // comes back as a numeric string. The array store used elsewhere doesn't.
    Cache::extend('numbers-as-strings', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            $value = parent::get($key);

            return is_int($value) || is_float($value) ? (string) $value : $value;
        }
    }));
    config()->set('cache.stores.stringy', ['driver' => 'numbers-as-strings']);
    config()->set('cache.default', 'stringy');
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    Http::fake(['prometheus.test:9090/api/v1/query_range*' => Http::response(rangeResponse(0.2, 0.5, 0.9))]);

    $read = fn (): array => app(SignalContext::class)->for(['service_name' => 'cbox-web'], new DateTimeImmutable('@1735689600'), new DateTimeImmutable('@1735689780'));

    $first = $read();
    $second = $read(); // the baseline now comes from the cache, as a string

    expect($second[0]->baseline)->toBeFloat()->toEqualWithDelta($first[0]->baseline, 1e-9);
});
