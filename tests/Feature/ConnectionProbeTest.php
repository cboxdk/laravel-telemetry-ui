<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\BackendStatus;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\WritesToBackend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * D3 — the connection probe. The point is precision: "it didn't work" is
 * useless, because a wrong hostname, an untrusted CA, a bad token and a URL
 * pointing at the wrong product need four different fixes.
 */
it('passes a healthy Prometheus and reports its version', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/status/buildinfo' => Http::response([
            'status' => 'success',
            'data' => ['version' => '2.53.0'],
        ]),
    ]);

    $result = app(ConnectionManager::class)->probe('metrics');

    expect($result->passed())->toBeTrue()
        ->and($result->status)->toBe(BackendStatus::Ok)
        ->and($result->version)->toBe('2.53.0');
});

it('falls back to a trivial query when buildinfo is absent', function (): void {
    // VictoriaMetrics and friends serve the query API but not buildinfo; a 404
    // there says nothing about whether the metrics API works.
    Http::fake([
        'prometheus.test:9090/api/v1/status/buildinfo' => Http::response('nope', 404),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    $result = app(ConnectionManager::class)->probe('metrics');

    expect($result->passed())->toBeTrue()
        ->and($result->version)->toBeNull();
});

it('reports unauthorized separately from unreachable', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('no', 401)]);

    expect(app(ConnectionManager::class)->probe('metrics')->status)
        ->toBe(BackendStatus::Unauthorized);
});

it('reports a TLS failure as TLS, not as unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
    ));

    $result = app(ConnectionManager::class)->probe('metrics');

    expect($result->status)->toBe(BackendStatus::Tls)
        ->and($result->message)->toContain('TLS');
});

it('reports a genuinely unreachable host as unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: tempo.test'));

    expect(app(ConnectionManager::class)->probe('traces')->status)
        ->toBe(BackendStatus::Unreachable);
});

it('detects a URL pointing at the wrong product', function (): void {
    // The classic paste error: a Loki URL in the traces field. It answers 200
    // with valid JSON, so only an API-shape check catches it — without this the
    // profile probes green and then every trace card fails.
    Http::fake([
        'tempo.test:3200/api/search/tags' => Http::response(['status' => 'success', 'data' => ['app']]),
    ]);

    $result = app(ConnectionManager::class)->probe('traces');

    expect($result->passed())->toBeFalse()
        ->and($result->status)->toBe(BackendStatus::UnexpectedApi);
});

it('passes a healthy Tempo and a healthy Loki', function (): void {
    Http::fake([
        'tempo.test:3200/api/search/tags' => Http::response(['tagNames' => ['service.name']]),
        'loki.test:3100/loki/api/v1/labels' => Http::response(['status' => 'success', 'data' => ['app']]),
    ]);

    $manager = app(ConnectionManager::class);

    expect($manager->probe('traces')->passed())->toBeTrue()
        ->and($manager->probe('logs')->passed())->toBeTrue();
});

it('returns a failed result rather than throwing on a broken config', function (): void {
    // A connection tester that can itself blow up is not a tester.
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'nonsense', 'url' => 'http://x']);

    $result = app(ConnectionManager::class)->probe('metrics');

    expect($result->passed())->toBeFalse()
        ->and($result->status)->toBe(BackendStatus::Error);

    config()->set('telemetry-ui.connections.metrics', null);

    expect(app(ConnectionManager::class)->probe('metrics')->passed())->toBeFalse();
});

it('never leaks the backend url into a probe message', function (): void {
    // Probe results render in the same semi-trusted dashboard as card errors,
    // so they follow the same disclosure rule.
    Http::fake(fn () => throw new ConnectionException('Could not resolve host: secret-internal.test'));

    expect(app(ConnectionManager::class)->probe('metrics')->message)
        ->not->toContain('secret-internal.test');
});

/**
 * D4 — the read-only posture is checkable, not just claimed.
 */
it('marks no telemetry read driver as writing to its backend', function (): void {
    Http::fake([
        'tempo.test:3200/*' => Http::response(['tagNames' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['version' => '2.53.0']]),
    ]);

    $manager = app(ConnectionManager::class);

    expect($manager->traces())->not->toBeInstanceOf(WritesToBackend::class)
        ->and($manager->logs())->not->toBeInstanceOf(WritesToBackend::class)
        ->and($manager->metrics())->not->toBeInstanceOf(WritesToBackend::class);
});

it('marks an issue tracker that can create tickets as writing', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_x',
    ]);

    expect(app(ConnectionManager::class)->issues())->toBeInstanceOf(WritesToBackend::class);
});
