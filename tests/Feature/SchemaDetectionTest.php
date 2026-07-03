<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function fakeMetricCount(float $count): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => $count > 0 ? [['metric' => [], 'value' => [1735689600, (string) $count]]] : [],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
    ]);
}

it('shows the statamic page when statamic metrics exist', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricCount(12);

    // The Statamic group and its per-family subpages appear.
    $this->get('/telemetry-ui')->assertOk()->assertSee('Statamic')->assertSee('Static Cache')->assertSee('Stache');
    $this->get('/telemetry-ui/statamic-cache')->assertOk()->assertSee('Static cache');
    $this->get('/telemetry-ui/statamic-glide')->assertOk()->assertSee('Glide');

    Http::assertSent(fn ($request): bool => str_contains(
        requestQuery($request)['query'] ?? '',
        'count({__name__=~"statamic_static_cache.*"})',
    ));
});

it('hides and 404s the statamic subpages when no statamic metrics exist', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricCount(0);

    $this->get('/telemetry-ui')->assertOk()->assertDontSee('Statamic');
    $this->get('/telemetry-ui/statamic-cache')->assertNotFound();
    $this->get('/telemetry-ui/statamic-glide')->assertNotFound();
});

it('caches detection results', function (): void {
    fakeMetricCount(1);

    $detector = app(SchemaDetector::class);

    expect($detector->hasMetricsMatching('statamic_.*'))->toBeTrue()
        ->and($detector->hasMetricsMatching('statamic_.*'))->toBeTrue();

    Http::assertSentCount(1);
});

it('fails open without caching when the metrics backend is down', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 503)]);

    $detector = app(SchemaDetector::class);

    expect($detector->hasMetricsMatching('statamic_.*'))->toBeTrue()
        ->and(cache()->get('telemetry-ui:detect:metrics:statamic_.*'))->toBeNull();
});

it('applies detection to third-party pages', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricCount(0);

    TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity', detectMetric: 'autoscale_.*');

    $this->get('/telemetry-ui/autoscale')->assertNotFound();
});
