<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\Loki\LokiSource;
use Cbox\TelemetryUi\Connectors\Prometheus\MimirSource;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\Tempo\TempoSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Illuminate\Support\Facades\Http;

it('resolves the default connections lazily from config', function (): void {
    $manager = app(ConnectionManager::class);

    expect($manager->metrics())->toBeInstanceOf(PrometheusSource::class)
        ->and($manager->traces())->toBeInstanceOf(TempoSource::class)
        ->and($manager->logs())->toBeInstanceOf(LokiSource::class);
});

it('resolves each connection once', function (): void {
    $manager = app(ConnectionManager::class);

    expect($manager->metrics())->toBe($manager->metrics());
});

it('resolves the mimir driver with its prometheus path prefix', function (): void {
    config()->set('telemetry-ui.connections.metrics.driver', 'mimir');
    config()->set('telemetry-ui.connections.metrics.tenant', 'team-apps');

    Http::fake([
        'prometheus.test:9090/prometheus/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    $metrics = app(ConnectionManager::class)->metrics();

    expect($metrics)->toBeInstanceOf(MimirSource::class);

    $metrics->query('up');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/prometheus/api/v1/query')
        && $request->hasHeader('X-Scope-OrgID', 'team-apps'));
});

it('supports additional named connections', function (): void {
    config()->set('telemetry-ui.connections.metrics-eu', [
        'driver' => 'prometheus',
        'url' => 'http://prometheus-eu.test:9090',
    ]);

    expect(app(ConnectionManager::class)->metrics('metrics-eu'))->toBeInstanceOf(PrometheusSource::class);
});

it('supports custom drivers via extend', function (): void {
    config()->set('telemetry-ui.connections.metrics.driver', 'victoriametrics');

    $manager = app(ConnectionManager::class);

    $manager->extend('victoriametrics', fn (array $config): MetricsSource => new class implements MetricsSource
    {
        public function query(string $promql, ?DateTimeInterface $at = null): array
        {
            return [];
        }

        public function queryRange(string $promql, DateTimeInterface $start, DateTimeInterface $end, ?int $step = null): array
        {
            return [];
        }

        public function labelValues(string $label, ?string $match = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): array
        {
            return [];
        }
    });

    expect($manager->metrics())->toBeInstanceOf(MetricsSource::class);
});

it('rejects unknown connections and drivers', function (): void {
    $manager = app(ConnectionManager::class);

    expect(fn () => $manager->metrics('nope'))->toThrow(InvalidArgumentException::class, 'not configured')
        ->and(function () use ($manager): void {
            config()->set('telemetry-ui.connections.weird', ['driver' => 'graphite', 'url' => 'http://x.test']);
            $manager->metrics('weird');
        })->toThrow(InvalidArgumentException::class, 'not supported');
});

it('rejects a connection resolved as the wrong signal type', function (): void {
    app(ConnectionManager::class)->metrics('traces');
})->throws(InvalidArgumentException::class, 'does not implement');
