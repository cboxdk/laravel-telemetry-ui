<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Events\BackendQueried;
use Cbox\TelemetryUi\Events\DashboardViewed;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

it('fires DashboardViewed on a page view (audit / usage metering)', function (): void {
    Event::fake([DashboardViewed::class]);
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $this->get('/telemetry-ui/requests?service=checkout&env=prod')->assertOk();

    Event::assertDispatched(DashboardViewed::class, fn (DashboardViewed $e): bool => $e->page === 'requests'
        && $e->service === 'checkout'
        && $e->environment === 'prod');
});

it('records an array-shaped scope param as empty, not the literal Array', function (): void {
    Event::fake([DashboardViewed::class]);
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    // ?service[]=x is an array — the audit event must record '' (not the literal
    // 'Array', and no "Array to string conversion" warning/500 in the controller).
    $this->get('/telemetry-ui/requests?service[]=x');

    Event::assertDispatched(DashboardViewed::class, fn (DashboardViewed $e): bool => $e->service === '');
});

it('fires BackendQueried for each real backend hit (load metering)', function (): void {
    Event::fake([BackendQueried::class]);

    Http::fake(['prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Event::assertDispatched(BackendQueried::class, fn (BackendQueried $e): bool => str_contains($e->url, 'prometheus.test:9090')
        && $e->method === 'GET'
        && $e->ok === true
        && $e->durationMs >= 0);
});
