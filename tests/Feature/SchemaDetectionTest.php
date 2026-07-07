<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Http\Client\Request;
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

    // Assert on the Statamic subpage labels, not the bare word "Statamic" —
    // the always-on "Statamic cache purge" annotation marker legitimately
    // carries it in the header regardless of detection.
    $this->get('/telemetry-ui')->assertOk()->assertDontSee('Static Cache')->assertDontSee('Stache');
    $this->get('/telemetry-ui/statamic-cache')->assertNotFound();
    $this->get('/telemetry-ui/statamic-glide')->assertNotFound();
});

it('scopes detection to the selected service', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    // The fake pretends only "has-statamic" emits statamic_* metrics: the
    // detection count comes back non-empty unless it is scoped to another
    // service. Non-statamic detection stays empty (those groups hide, fine).
    Http::fake(function (Request $request) {
        $query = requestQuery($request)['query'] ?? '';

        if (str_contains($query, 'count(') && str_contains($query, 'statamic_')) {
            $present = ! str_contains($query, 'service_name="no-statamic"');

            return Http::response([
                'status' => 'success',
                'data' => [
                    'resultType' => 'vector',
                    'result' => $present ? [['metric' => [], 'value' => [1735689600, '5']]] : [],
                ],
            ]);
        }

        return Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]);
    });

    // A service that emits statamic_* keeps the group…
    $this->get('/telemetry-ui?service=has-statamic')->assertOk()->assertSee('Statamic');

    // …a service that doesn't drops it, even though the fleet has it elsewhere.
    // (Check the subpage labels, not "Statamic": the annotation marker carries it.)
    $this->get('/telemetry-ui?service=no-statamic')->assertOk()->assertDontSee('Static Cache')->assertDontSee('Stache');
    $this->get('/telemetry-ui/statamic-cache?service=no-statamic')->assertNotFound();

    // The scoped count query carries the service matcher.
    Http::assertSent(fn ($request): bool => str_contains(
        requestQuery($request)['query'] ?? '',
        'service_name="no-statamic"',
    ));
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
