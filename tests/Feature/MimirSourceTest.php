<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\Prometheus\MimirSource;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Illuminate\Support\Facades\Http;

it('queries the prometheus api under the mimir prefix with the tenant header', function (): void {
    Http::fake([
        'mimir.test/prometheus/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    $source = new MimirSource(new ApiClient('http://mimir.test', tenant: 'team-apps'));

    $source->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'http://mimir.test/prometheus/api/v1/query')
        && $request->hasHeader('X-Scope-OrgID', 'team-apps'));
});

it('sends custom headers alongside the tenant', function (): void {
    Http::fake([
        'mimir.test/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    $source = new MimirSource(new ApiClient(
        'http://mimir.test',
        headers: ['Authorization' => 'Bearer secret'],
        tenant: 'team-apps',
    ));

    $source->query(MetricQuery::raw('up'));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret')
        && $request->hasHeader('X-Scope-OrgID', 'team-apps'));
});
