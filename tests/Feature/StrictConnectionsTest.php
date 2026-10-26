<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\BackendStatus;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;

/*
 * A strict resolver is the only source of connections: what it does not hand
 * out is refused, never quietly read from the static config — which, on a
 * multi-tenant host, points at somebody else's data or at nothing at all.
 */

beforeEach(function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []], 'traces' => []])]);
});

it('refuses a connection a strict resolver omits instead of falling back to static config', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [
        'metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant.test:9090'],
    ], strict: true);

    $connections = app(ConnectionManager::class);

    // The connection it hands out works as before…
    $connections->metrics()->query(MetricQuery::raw('up'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tenant.test:9090'));

    // …and the one it omits is refused, not read from tempo.test.
    expect(fn () => $connections->traces()->search(TraceQuery::raw('{}'), new DateTimeImmutable('-1 hour'), new DateTimeImmutable))
        ->toThrow(SourceException::class);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'tempo.test'));
});

it('fails closed for a request without a viewer', function (): void {
    $called = false;

    app(TelemetryUiManager::class)->resolveConnectionsUsing(function ($user) use (&$called): array {
        $called = true;

        return ['metrics' => ['driver' => 'prometheus', 'url' => 'http://tenant.test:9090']];
    }, strict: true);

    try {
        app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));
        $refused = null;
    } catch (SourceException $exception) {
        $refused = $exception;
    }

    expect($refused)->not->toBeNull()
        ->and($refused?->status)->toBe(BackendStatus::Unauthorized)
        ->and($called)->toBeFalse();
    Http::assertNothingSent();
});

it('answers a refused connection with a typed backend error, not a 500', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [], strict: true);

    $this->getJson(panelUrl('trace-search'))
        ->assertStatus(502)
        ->assertJsonPath('error.type', 'backend')
        ->assertJsonPath('error.backend', 'unauthorized');

    Http::assertNothingSent();
});

it('reports no issue tracker when a strict resolver hands none out', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));
    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'acme/static', 'token' => 'x']);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => [], strict: true);

    expect(app(ConnectionManager::class)->hasIssues())->toBeFalse()
        ->and(app(ConnectionManager::class)->issueSources())->toBe([]);
});

it('keeps the default resolver non-strict', function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));
    app(TelemetryUiManager::class)->resolveConnectionsUsing(fn ($user): array => []);

    expect(app(TelemetryUiManager::class)->connectionResolverIsStrict())->toBeFalse()
        ->and(app(ConnectionManager::class)->failsClosed())->toBeFalse();

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'prometheus.test'));
});
