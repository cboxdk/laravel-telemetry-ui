<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Events\BackendQueried;
use Cbox\TelemetryUi\Events\DashboardViewed;
use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\ViewState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('fires DashboardViewed when the spa shell is served (audit / usage metering)', function (string $path, string $page): void {
    Event::fake([DashboardViewed::class]);

    $this->get($path.'?service=checkout&env=prod')->assertOk();

    Event::assertDispatched(DashboardViewed::class, fn (DashboardViewed $e): bool => $e->page === $page
        && $e->service === 'checkout'
        && $e->environment === 'prod');
})->with([
    ['/telemetry-ui', 'dashboard'],
    ['/telemetry-ui/p/requests', 'requests'],
    ['/telemetry-ui/explore/logs', 'logs'],
    ['/telemetry-ui/traces/abc123', 'traces'],
]);

it('does not fire DashboardViewed for api reads, which are not page views', function (): void {
    Event::fake([DashboardViewed::class]);

    Http::fake(['prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    $this->getJson(apiUrl('pages/requests'))->assertOk();
    $this->getJson(panelUrl('routes-table'))->assertOk();

    Event::assertNotDispatched(DashboardViewed::class);
});

it('records an array-shaped scope param as empty, not the literal Array', function (): void {
    Event::fake([DashboardViewed::class]);

    // ?service[]=x is an array — the audit event must record '' (not the literal
    // 'Array', and no "Array to string conversion" warning/500 in the controller).
    $this->get('/telemetry-ui/p/requests?service[]=x')->assertOk();

    Event::assertDispatched(DashboardViewed::class, fn (DashboardViewed $e): bool => $e->service === '');
});

it('fires ViewStateChanged when the spa reports a move, and stays quiet otherwise', function (): void {
    Event::fake([ViewStateChanged::class]);

    $this->postJson(apiUrl('view-state'), ['period' => '7d'])->assertOk();

    Event::assertDispatched(ViewStateChanged::class, fn (ViewStateChanged $e): bool => $e->state->period()->value === '7d');

    Event::fake([ViewStateChanged::class]);

    // Reporting the window the reader already has is not a change.
    $this->withCredentials()->withUnencryptedCookies([ViewState::DEFAULT_COOKIE => 'period=7d&service=&env='])
        ->postJson(apiUrl('view-state'), ['period' => '7d'])
        ->assertOk();

    Event::assertNotDispatched(ViewStateChanged::class);
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
