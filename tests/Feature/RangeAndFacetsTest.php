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
    $this->getJson(panelUrl('request-duration', ['from' => '1735686000', 'to' => '1735689600']))->assertOk();

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

    $this->getJson(panelUrl('request-duration', ['from' => '999', 'to' => '1', 'period' => '1h']))->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query?')
        && str_contains(requestQuery($request)['query'] ?? '', '[3600s]'));
});

it('facets traffic by user via user.id', function (): void {
    fakeEmptyBackends();

    $this->getJson(panelUrl('traffic-by-facet', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('controls.0.param', 'facet')
        ->assertJsonPath('controls.0.value', 'user')
        ->assertJsonPath('columns.0.label', 'User');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search')
        && str_contains(requestQuery($request)['q'] ?? '', 'span.user.id != nil')
        && str_contains(requestQuery($request)['q'] ?? '', 'select(span.user.id)'));
});

it('facets traffic by client ip', function (): void {
    fakeEmptyBackends();

    $this->getJson(panelUrl('traffic-by-facet', ['period' => '1h', 'facet' => 'ip']))
        ->assertOk()
        ->assertJsonPath('columns.0.label', 'Client IP');

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

    $this->getJson(panelUrl('service-graph', ['period' => '1h']))
        ->assertOk()
        ->assertSee('Service graph')
        ->assertSee('traefik')
        ->assertSee('checkout');
});

/**
 * A Tempo search hit whose matched span carries one attribute.
 *
 * @param  array<string, string>  $attributes
 * @return array<string, mixed>
 */
function facetTrace(string $traceId, string $name, array $attributes): array
{
    $now = time();

    return [
        'traceID' => $traceId,
        'rootServiceName' => 'cbox-web',
        'rootTraceName' => $name,
        'startTimeUnixNano' => (string) ($now * 1_000_000_000),
        'durationMs' => 42,
        'spanSets' => [['spans' => [[
            'spanID' => substr($traceId, 0, 16),
            'name' => $name,
            'startTimeUnixNano' => (string) ($now * 1_000_000_000),
            'durationNanos' => '42000000',
            'attributes' => array_map(
                static fn (string $key, string $value): array => ['key' => $key, 'value' => ['stringValue' => $value]],
                array_keys($attributes),
                array_values($attributes),
            ),
        ]]]],
    ];
}

it('lets the SPA filter by a declared facet dimension and drill into its traces', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            facetTrace('2222222222222222bbbbbbbbbbbbbbbb', 'GET /orders', ['client.address' => '10.0.0.7']),
        ]]),
    ]);

    $this->getJson(panelUrl('traffic-by-facet', ['period' => '1h', 'facet' => 'ip']))
        ->assertOk()
        ->assertJsonPath('rows.0.value.v', '10.0.0.7')
        ->assertJsonPath('rows.0.value.dim', ['key' => 'client.address', 'value' => '10.0.0.7'])
        ->assertJsonPath('rows.0.traces.v', 1)
        ->assertJsonPath('rows.0.lastAction.v', 'GET /orders')
        ->assertJsonPath('rows.0._link', ['to' => 'explore', 'signal' => 'requests', 'where' => ['client.address=10.0.0.7']]);
});

it('asks for an attribute when the custom facet is empty, with a search control', function (): void {
    fakeEmptyBackends();

    $this->getJson(panelUrl('traffic-by-facet', ['period' => '1h', 'facet' => 'custom']))
        ->assertOk()
        ->assertJsonPath('controls.1.param', 'facet_attr')
        ->assertJsonPath('controls.1.type', 'search')
        ->assertJsonPath('error', 'Enter a span attribute, e.g. team.id or statamic.site.');

    Http::assertNothingSent();
});

it('does not tag an undeclared custom attribute as a dimension', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            facetTrace('3333333333333333cccccccccccccccc', 'GET /teams', ['team.id' => '42']),
        ]]),
    ]);

    $this->getJson(panelUrl('traffic-by-facet', ['period' => '1h', 'facet' => 'custom', 'facet_attr' => 'team.id']))
        ->assertOk()
        ->assertJsonPath('columns.0.label', 'team.id')
        ->assertJsonPath('rows.0.value.v', '42')
        ->assertJsonMissingPath('rows.0.value.dim');
});

it('tones core web vitals per page on Google thresholds', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            [
                'traceID' => '4444444444444444dddddddddddddddd',
                'rootServiceName' => 'cbox-web',
                'rootTraceName' => 'web-vitals',
                'startTimeUnixNano' => (string) (time() * 1_000_000_000),
                'durationMs' => 1,
                'spanSets' => [['spans' => [[
                    'spanID' => 'b1',
                    'name' => 'web-vitals',
                    'startTimeUnixNano' => (string) (time() * 1_000_000_000),
                    'durationNanos' => '1000000',
                    'attributes' => [
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://cbox.dk/pricing?x=1']],
                        ['key' => 'web_vitals.lcp_ms', 'value' => ['doubleValue' => 5200.0]],
                        ['key' => 'web_vitals.cls', 'value' => ['doubleValue' => 0.05]],
                        ['key' => 'web_vitals.inp_ms', 'value' => ['doubleValue' => 300.0]],
                    ],
                ]]]],
            ],
        ]]),
    ]);

    $this->getJson(panelUrl('web-vitals', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('parts.0.kind', 'stats')
        ->assertJsonPath('parts.1.rows.0.path.v', '/pricing')
        ->assertJsonPath('parts.1.rows.0.lcp.tone', 'danger')
        ->assertJsonPath('parts.1.rows.0.cls.tone', 'ok')
        ->assertJsonPath('parts.1.rows.0.cls.v', '0.05')
        ->assertJsonPath('parts.1.rows.0.inp.tone', 'warn')
        ->assertJsonPath('parts.1.rows.0._link', ['to' => 'entity', 'type' => 'path', 'value' => '/pricing']);
});

it('groups failed browser fetches by url and links a representative trace', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            facetTrace('5555555555555555eeeeeeeeeeeeeeee', 'fetch POST', [
                'http.url' => 'https://cbox.dk/api/cart?id=9',
                'http.response.status_code' => '502',
            ]),
        ]]),
    ]);

    $this->getJson(panelUrl('frontend-fetches', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonPath('rows.0.url.v', 'https://cbox.dk/api/cart')
        ->assertJsonPath('rows.0.status.v', '502')
        ->assertJsonPath('rows.0.status.tone', 'danger')
        ->assertJsonPath('rows.0.count.raw', 1)
        ->assertJsonPath('rows.0._link', ['to' => 'trace', 'id' => '5555555555555555eeeeeeeeeeeeeeee']);
});
