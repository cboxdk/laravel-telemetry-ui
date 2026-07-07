<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;

it('resolves connection config per viewer, overriding the static config', function (): void {
    // A per-tenant resolver only fires for an actual viewer — an unauthenticated
    // or boot-time context falls through to static config (no null deref, no
    // resolver I/O at boot).
    $this->actingAs(new GenericUser(['id' => 1]));

    // Static config points at the default backend…
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'prometheus', 'url' => 'http://default:9090']);

    // …but the tenant resolver points this viewer at their own Mimir.
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'mimir', 'url' => 'http://tenant-a:9009', 'tenant' => 'team-a'],
    ]);

    Http::fake(['tenant-a:9009/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tenant-a:9009')
        && ($request->header('X-Scope-OrgID')[0] ?? null) === 'team-a');
});

it('reports issues from the per-tenant resolver, not just static config', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));

    // No static issues connection — only the resolver provides one for this viewer.
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'issues' => ['driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_y'],
    ]);

    // hasIssues() consults the resolver like issues() does — the create-ticket
    // gate and the trace-drawer label agree with the tracker actually resolved.
    expect(app(ConnectionManager::class)->hasIssues())->toBeTrue();
});

it('falls back to the static config for connections the resolver omits', function (): void {
    config()->set('telemetry-ui.connections.traces', ['driver' => 'tempo', 'url' => 'http://static-tempo:3200']);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant:9090'], // traces not overridden
    ]);

    Http::fake(['static-tempo:3200/*' => Http::response(['traces' => []])]);

    app(ConnectionManager::class)->traces()->search(TraceQuery::raw('{}'), new DateTimeImmutable('-1 hour'), new DateTimeImmutable);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'static-tempo:3200'));
});

it('does not hand one tenant another tenant\'s cached driver', function (): void {
    // The resolver is memoised per request, so two tenants are two requests —
    // forgetScopedInstances() is the Octane request boundary. The ConnectionManager
    // singleton (and its config-keyed driver cache) survives it, like a worker.
    $this->actingAs(new GenericUser(['id' => 1]));

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant-'.$GLOBALS['tenant'].':9090'],
    ]);

    Http::fake([
        'tenant-a:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tenant-b:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $manager = app(ConnectionManager::class);

    $GLOBALS['tenant'] = 'a';
    $manager->metrics()->query(MetricQuery::raw('up'));

    app()->forgetScopedInstances(); // next request: the per-viewer memo resets
    $GLOBALS['tenant'] = 'b';
    $manager->metrics()->query(MetricQuery::raw('up'));

    // Each tenant's query hit its own backend — the config-keyed cache didn't
    // reuse tenant a's driver for tenant b.
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'tenant-a:9090'));
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'tenant-b:9090'));

    unset($GLOBALS['tenant']);
});
