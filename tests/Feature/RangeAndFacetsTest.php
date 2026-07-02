<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function fakeEmptyBackends(): void
{
    Http::fake([
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
    ]);
}

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
});

it('applies a custom absolute range to metric queries', function (): void {
    fakeEmptyBackends();
    $this->get('/telemetry-ui/requests?from=1735686000&to=1735689600')->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'query_range')) {
            return false;
        }

        $query = requestQuery($request);

        return $query['start'] === '1735686000' && $query['end'] === '1735689600';
    });

    // Instant totals use the custom range length (3600s), not the preset.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query?')
        && str_contains(requestQuery($request)['query'] ?? '', '[3600s]'));
});

it('ignores an invalid custom range and falls back to the period', function (): void {
    fakeEmptyBackends();

    $this->get('/telemetry-ui/requests?from=999&to=1&period=1h')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query?')
        && str_contains(requestQuery($request)['query'] ?? '', '[3600s]'));
});

it('facets traffic by user via enduser.id', function (): void {
    fakeEmptyBackends();

    $this->get('/telemetry-ui/users')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search')
        && str_contains(requestQuery($request)['q'] ?? '', 'span.enduser.id != nil')
        && str_contains(requestQuery($request)['q'] ?? '', 'select(span.enduser.id)'));
});

it('facets traffic by client ip', function (): void {
    fakeEmptyBackends();

    $this->get('/telemetry-ui/users?facet=ip')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search')
        && str_contains(requestQuery($request)['q'] ?? '', 'span.client.address != nil'));
});

it('renders service graph edges', function (): void {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/search')) {
            return Http::response(['traces' => []]);
        }

        $query = requestQuery($request)['query'] ?? '';

        if (str_contains($query, 'traces_service_graph_request_total')) {
            return Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'vector', 'result' => [
                    ['metric' => ['client' => 'traefik', 'server' => 'checkout'], 'value' => [1735689600, '120']],
                ]],
            ]);
        }

        return Http::response([
            'status' => 'success',
            'data' => ['resultType' => str_contains($request->url(), 'query_range') ? 'matrix' : 'vector', 'result' => []],
        ]);
    });

    $this->get('/telemetry-ui/traces')
        ->assertOk()
        ->assertSee('Service graph')
        ->assertSee('traefik')
        ->assertSee('checkout');
});
