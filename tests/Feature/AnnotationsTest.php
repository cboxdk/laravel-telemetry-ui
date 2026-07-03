<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\Annotations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function fakeDeployMarkers(): void
{
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'telemetry-demo',
                        'deployment_id' => 'abc123',
                        'deployment_notes' => 'hotfix: cache TTL',
                        'trace_id' => 'aaaabbbbccccddddeeeeffff00001111',
                    ],
                    'values' => [
                        ['1735689600000000000', 'app.deployment'],
                        ['1735689500000000000', 'some unrelated line mentioning app.deployment inline'],
                    ],
                ],
            ]],
        ]),
    ]);
}

it('reads deploy markers from loki within a range', function (): void {
    fakeDeployMarkers();

    $annotations = app(Annotations::class)->between(
        new DateTimeImmutable('@1735689000'),
        new DateTimeImmutable('@1735690000'),
        ['service_name' => 'telemetry-demo'],
    );

    // Only the exact "app.deployment" line becomes a marker; the inline
    // mention is ignored.
    expect($annotations)->toHaveCount(1)
        ->and($annotations[0]->label)->toBe('Deploy abc123')
        ->and($annotations[0]->notes)->toBe('hotfix: cache TTL')
        ->and($annotations[0]->traceId)->toBe('aaaabbbbccccddddeeeeffff00001111')
        ->and($annotations[0]->timestampMs)->toBe(1735689600000.0);

    Http::assertSent(fn ($request): bool => str_contains(requestQuery($request)['query'] ?? '', 'app.deployment')
        && str_contains(requestQuery($request)['query'] ?? '', 'service_name="telemetry-demo"'));
});

it('filters markers to the requested window', function (): void {
    fakeDeployMarkers();

    $annotations = app(Annotations::class)->between(
        new DateTimeImmutable('@1735680000'),
        new DateTimeImmutable('@1735685000'),
        [],
    );

    expect($annotations)->toBe([]);
});

it('can be disabled', function (): void {
    config()->set('telemetry-ui.annotations.enabled', false);

    expect(app(Annotations::class)->lookback([]))->toBe([]);

    Http::assertNothingSent();
});

it('fails open when the logs backend is down', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('down', 503)]);

    expect(app(Annotations::class)->lookback(['service_name' => 'x']))->toBe([]);
});

it('draws deploy annotation lines on dashboard charts', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '5']]],
            ]],
        ]),
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                ['stream' => ['deployment_id' => 'abc123', 'deployment_notes' => 'hotfix'], 'values' => [[(string) (time() * 1_000_000_000), 'app.deployment']]],
            ]],
        ]),
    ]);

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('Deploys')
        ->assertSee('hotfix');
});
