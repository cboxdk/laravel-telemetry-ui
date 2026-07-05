<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Events\BackendQueried;
use Cbox\TelemetryUi\Events\DashboardViewed;
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

it('fires BackendQueried for each real backend hit (load metering)', function (): void {
    Event::fake([BackendQueried::class]);

    Http::fake(['prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query('up');

    Event::assertDispatched(BackendQueried::class, fn (BackendQueried $e): bool => str_contains($e->url, 'prometheus.test:9090')
        && $e->method === 'GET'
        && $e->ok === true
        && $e->durationMs >= 0);
});
