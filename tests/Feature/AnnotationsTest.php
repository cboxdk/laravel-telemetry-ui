<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
        '{service_name="telemetry-demo"}',
    );

    // Only the exact "app.deployment" line becomes a marker; the inline
    // mention is ignored.
    expect($annotations)->toHaveCount(1)
        ->and($annotations[0]->label)->toBe('Deploy abc123')
        ->and($annotations[0]->notes)->toBe('hotfix: cache TTL')
        ->and($annotations[0]->traceId)->toBe('aaaabbbbccccddddeeeeffff00001111')
        ->and($annotations[0]->timestampMs)->toBe(1735689600000.0);

    // One combined regex query matches every marker type (not one query each).
    Http::assertSent(function ($request): bool {
        $q = requestQuery($request)['query'] ?? '';

        return str_contains($q, '|~')
            && str_contains($q, 'deployment') && str_contains($q, 'incident')
            && str_contains($q, 'service_name="telemetry-demo"');
    });
});

it('shapes markers for the chart tooltip/callout with time, kind and trace id', function (): void {
    $annotation = new Annotation(
        timestampMs: 1735689600000.0,
        label: 'Deploy abc123',
        notes: 'hotfix: cache TTL',
        kind: 'app.deployment',
        traceId: 'aaaabbbbccccddddeeeeffff00001111',
    );

    expect($annotation->toMarkLine())->toMatchArray([
        'xAxis' => 1735689600000.0,
        'label' => 'Deploy abc123',
        'notes' => 'hotfix: cache TTL',
        'kind' => 'app.deployment',
        'traceId' => 'aaaabbbbccccddddeeeeffff00001111',
        'color' => '#c084fc',
    ])->toHaveKey('time');
});

it('clusters a horizontal rollout into one marker with hosts and span', function (): void {
    // The same deploy id from three hosts within minutes (a rolling deploy),
    // plus an UNRELATED older deploy well outside the gap window.
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => ['service_name' => 'demo', 'deployment_id' => 'v2.4.1', 'host_name' => 'web-1'],
                    'values' => [['1735689600000000000', 'app.deployment']],
                ],
                [
                    'stream' => ['service_name' => 'demo', 'deployment_id' => 'v2.4.1', 'host_name' => 'web-2'],
                    'values' => [['1735689660000000000', 'app.deployment']],
                ],
                [
                    'stream' => ['service_name' => 'demo', 'deployment_id' => 'v2.4.1', 'host_name' => 'web-3'],
                    'values' => [['1735689720000000000', 'app.deployment']],
                ],
                [
                    'stream' => ['service_name' => 'demo', 'deployment_id' => 'v2.4.0', 'host_name' => 'web-1'],
                    'values' => [['1735600000000000000', 'app.deployment']],
                ],
            ]],
        ]),
    ]);

    $annotations = app(Annotations::class)->between(
        new DateTimeImmutable('@1735500000'),
        new DateTimeImmutable('@1735700000'),
    );

    expect($annotations)->toHaveCount(2);

    $rollout = $annotations[0]; // newest first
    expect($rollout->label)->toBe('Deploy v2.4.1')
        ->and($rollout->count)->toBe(3)
        ->and($rollout->timestampMs)->toBe(1735689600000.0)   // rollout start
        ->and($rollout->endMs)->toBe(1735689720000.0)         // rollout end
        ->and($rollout->hosts)->toBe(['web-1', 'web-2', 'web-3']);

    expect($annotations[1]->label)->toBe('Deploy v2.4.0')
        ->and($annotations[1]->count)->toBe(1)
        ->and($annotations[1]->endMs)->toBeNull();

    // The chart payload carries the cluster facts for the callout.
    expect($rollout->toMarkLine())->toMatchArray(['count' => 3, 'hostCount' => 3])
        ->and($rollout->toMarkLine()['timeEnd'])->not->toBeNull();
});

it('reads statamic cache purges as cache purge markers', function (): void {
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'telemetry-demo',
                        'cache_type' => 'static',
                        'cache_trigger' => 'http',
                    ],
                    'values' => [
                        ['1735689600000000000', 'statamic.cache.purge'],
                    ],
                ],
            ]],
        ]),
    ]);

    $annotations = app(Annotations::class)->between(
        new DateTimeImmutable('@1735689000'),
        new DateTimeImmutable('@1735690000'),
    );

    expect($annotations)->toHaveCount(1)
        ->and($annotations[0]->label)->toBe('Cache purge static')
        ->and($annotations[0]->notes)->toBe('http')
        ->and($annotations[0]->kind)->toBe('statamic.cache.purge')
        ->and($annotations[0]->color)->toBe('#fb923c');
});

it('filters markers to the requested window', function (): void {
    fakeDeployMarkers();

    $annotations = app(Annotations::class)->between(
        new DateTimeImmutable('@1735680000'),
        new DateTimeImmutable('@1735685000'),
    );

    expect($annotations)->toBe([]);
});

it('can be disabled', function (): void {
    config()->set('telemetry-ui.annotations.enabled', false);

    expect(app(Annotations::class)->lookback())->toBe([]);

    Http::assertNothingSent();
});

it('fails open when the logs backend is down', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('down', 503)]);

    expect(app(Annotations::class)->lookback('{service_name="x"}'))->toBe([]);
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

it('hides annotation types toggled off via ann_off', function (): void {
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
                ['stream' => ['deployment_id' => 'abc123'], 'values' => [[(string) (time() * 1_000_000_000), 'app.deployment']]],
            ]],
        ]),
    ]);

    // Drawn by default…
    expect(Livewire::test(JobsOverview::class)->html())->toContain('abc123');

    // …and gone when the deploy marker type is toggled off in the header.
    expect(Livewire::withQueryParams(['ann_off' => 'deploy'])->test(JobsOverview::class)->html())
        ->not->toContain('abc123');
});
