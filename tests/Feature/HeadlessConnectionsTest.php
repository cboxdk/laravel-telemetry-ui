<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\StreamLabels;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * ConnectionManager::using() — a scheduled command or a queued job reading
 * one tenant's backends, with no viewer to resolve them from.
 */

/** @return array<string, array<string, mixed>> */
function tenantConnections(string $tenant): array
{
    return [
        'metrics' => ['driver' => 'prometheus', 'url' => "http://tenant-{$tenant}.test:9090"],
        'logs' => ['driver' => 'loki', 'url' => "http://tenant-{$tenant}.test:3100"],
    ];
}

beforeEach(function (): void {
    Http::fake(function (Request $request) {
        $tenant = str_contains($request->url(), 'tenant-a.test') ? 'a' : 'b';

        return match (true) {
            str_contains($request->url(), '/api/v1/label/') => Http::response(['status' => 'success', 'data' => ["svc-{$tenant}"]]),
            str_contains($request->url(), '/loki/api/v1/labels') => Http::response(['status' => 'success', 'data' => $tenant === 'a' ? ['service_name', 'deployment_environment_name'] : ['service_name']]),
            default => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        };
    });
});

it('reads the given connections with no viewer and no resolver call', function (): void {
    $called = false;
    app(TelemetryUiManager::class)->resolveConnectionsUsing(function ($user) use (&$called): array {
        $called = true;

        return [];
    }, needsViewer: false);

    $result = app(ConnectionManager::class)->using(tenantConnections('a'), function (): string {
        app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

        return 'done';
    });

    expect($result)->toBe('done')->and($called)->toBeFalse();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tenant-a.test:9090'));
});

it('puts the previous connections back, also when the callback throws', function (): void {
    $connections = app(ConnectionManager::class);
    $outside = $connections->fingerprint();

    $connections->using(tenantConnections('a'), function () use ($connections, $outside): void {
        $a = $connections->fingerprint();
        expect($a)->not->toBe($outside);

        // Nested: the inner map wins, and the outer one is back after it.
        $connections->using(tenantConnections('b'), fn () => expect($connections->fingerprint())->not->toBe($a));
        expect($connections->fingerprint())->toBe($a);
    });

    expect(fn () => $connections->using(tenantConnections('a'), fn () => throw new RuntimeException('job failed')))
        ->toThrow(RuntimeException::class);

    expect($connections->fingerprint())->toBe($outside);
});

it('falls back to static config for what the map omits, unless the resolver is strict', function (): void {
    $connections = app(ConnectionManager::class);

    expect($connections->using(tenantConnections('a'), fn () => $connections->traces()))->toBeInstanceOf(TracesSource::class);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [], strict: true);

    expect(fn () => $connections->using(tenantConnections('a'), fn () => $connections->traces()))
        ->toThrow(SourceException::class);
});

it('keeps each tenant\'s cached answers apart across blocks in one process', function (): void {
    // One command walking tenants: no request boundary between the blocks,
    // so neither the shared cache nor a request-scoped memo may carry over.
    $connections = app(ConnectionManager::class);

    $read = fn (): array => [app(Fleet::class)->services(), app(StreamLabels::class)->indexes('deployment_environment_name')];

    expect($connections->using(tenantConnections('a'), $read))->toBe([['svc-a'], true])
        ->and($connections->using(tenantConnections('b'), $read))->toBe([['svc-b'], false])
        ->and($connections->using(tenantConnections('a'), $read))->toBe([['svc-a'], true]);
});
