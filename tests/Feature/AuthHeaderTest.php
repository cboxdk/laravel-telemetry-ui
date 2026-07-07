<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Illuminate\Support\Facades\Http;

it('adds a Bearer token from config as an Authorization header', function (): void {
    config()->set('telemetry-ui.connections.metrics.token', 'glsa_prod_token');

    Http::fake(['prometheus.test:9090/*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => []],
    ])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer glsa_prod_token'));
});

it('supports basic auth as user:pass', function (): void {
    config()->set('telemetry-ui.connections.traces.basic_auth', 'admin:secret');

    Http::fake(['tempo.test:3200/*' => Http::response(['traces' => []])]);

    app(ConnectionManager::class)->traces()->search(TraceQuery::raw('{}'), new DateTimeImmutable('-1 hour'), new DateTimeImmutable);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Basic '.base64_encode('admin:secret')));
});

it('lets an explicit Authorization header win over token', function (): void {
    config()->set('telemetry-ui.connections.metrics.token', 'ignored');
    config()->set('telemetry-ui.connections.metrics.headers', ['Authorization' => 'Bearer explicit']);

    Http::fake(['prometheus.test:9090/*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => []],
    ])]);

    app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer explicit'));
});
