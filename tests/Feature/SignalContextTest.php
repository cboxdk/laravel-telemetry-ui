<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\MetricSummary;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
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
        ->and(round($summaries[0]->avg, 3))->toBe(0.533);

    // The {scope} token expanded to the label matchers.
    Http::assertSent(fn ($request): bool => str_contains(
        rawurldecode($request->url()),
        'system_cpu_utilization_ratio{service_name="cbox-web",host_name="web-1"}',
    ));
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
