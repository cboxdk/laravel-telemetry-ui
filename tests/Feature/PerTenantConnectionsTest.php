<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

it('resolves connection config per viewer, overriding the static config', function (): void {
    // Static config points at the default backend…
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'prometheus', 'url' => 'http://default:9090']);

    // …but the tenant resolver points this viewer at their own Mimir.
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'mimir', 'url' => 'http://tenant-a:9009', 'tenant' => 'team-a'],
    ]);

    Http::fake(['tenant-a:9009/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query('up');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tenant-a:9009')
        && ($request->header('X-Scope-OrgID')[0] ?? null) === 'team-a');
});

it('falls back to the static config for connections the resolver omits', function (): void {
    config()->set('telemetry-ui.connections.traces', ['driver' => 'tempo', 'url' => 'http://static-tempo:3200']);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant:9090'], // traces not overridden
    ]);

    Http::fake(['static-tempo:3200/*' => Http::response(['traces' => []])]);

    app(ConnectionManager::class)->traces()->search('{}', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'static-tempo:3200'));
});

it('does not hand one tenant another tenant\'s cached driver', function (): void {
    $tenant = 'a';
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user) => [
        'metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant-'.$GLOBALS['tenant'].':9090'],
    ]);

    Http::fake([
        'tenant-a:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tenant-b:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $manager = app(ConnectionManager::class);

    $GLOBALS['tenant'] = 'a';
    $manager->metrics()->query('up');
    $GLOBALS['tenant'] = 'b';
    $manager->metrics()->query('up');

    // Each tenant's query hit its own backend — the config-keyed cache didn't
    // reuse tenant a's driver for tenant b.
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'tenant-a:9090'));
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'tenant-b:9090'));

    unset($GLOBALS['tenant']);
});
