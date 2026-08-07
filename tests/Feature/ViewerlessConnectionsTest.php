<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;

/**
 * D1 — a connection resolver for hosts with no authentication at all, where the
 * connection is a property of the process rather than of a user (a desktop
 * client bound to one profile). The default stays viewer-gated so tenant
 * resolvers, which dereference the user, are never handed null.
 */
it('consults a needsViewer:false resolver with no authenticated user', function (): void {
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'prometheus', 'url' => 'http://static:9090']);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(
        fn (mixed $viewer = null): array => [
            'metrics' => ['driver' => 'prometheus', 'url' => 'http://profile:9090'],
        ],
        needsViewer: false,
    );

    Http::fake(['profile:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'profile:9090'));
});

it('passes null as the viewer when there is none', function (): void {
    $seen = 'not-called';

    app(TelemetryUiManager::class)->resolveConnectionsUsing(
        function (mixed $viewer = null) use (&$seen): array {
            $seen = $viewer;

            return ['metrics' => ['driver' => 'prometheus', 'url' => 'http://profile:9090']];
        },
        needsViewer: false,
    );

    Http::fake(['profile:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    expect($seen)->toBeNull();
});

it('still hands an authenticated viewer to a needsViewer:false resolver', function (): void {
    // needsViewer:false widens WHEN the resolver runs; it does not blind it to a
    // viewer that does exist.
    $user = new GenericUser(['id' => 7]);
    $this->actingAs($user);

    $seen = null;

    app(TelemetryUiManager::class)->resolveConnectionsUsing(
        function (mixed $viewer = null) use (&$seen): array {
            $seen = $viewer;

            return ['metrics' => ['driver' => 'prometheus', 'url' => 'http://profile:9090']];
        },
        needsViewer: false,
    );

    Http::fake(['profile:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    expect($seen)->toBe($user);
});

it('leaves the default resolver viewer-gated', function (): void {
    // Regression guard for the BC risk in D1: a tenant resolver written as
    // fn ($user) => $user->tenant->connections must never be invoked with null.
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'prometheus', 'url' => 'http://static:9090']);

    app(TelemetryUiManager::class)->resolveConnectionsUsing(function (mixed $viewer): array {
        throw new RuntimeException('resolver must not run without a viewer');
    });

    Http::fake(['static:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'static:9090'));
});
