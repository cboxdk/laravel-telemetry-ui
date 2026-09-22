<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus'));

/**
 * Three request spans and three log lines. The first matching fake wins, so
 * overrides go in front.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeFacetBackends(array $overrides = []): void
{
    $now = time();

    $defaults = [
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0001', 'GET /orders', $now - 50, 80.0, ['http.request.method' => 'GET', 'http.route' => '/orders', 'http.response.status_code' => 200, 'hubhus.customer_id' => '8655']),
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0002', 'GET /orders', $now - 40, 95.0, ['http.request.method' => 'GET', 'http.route' => '/orders', 'http.response.status_code' => 500, 'hubhus.customer_id' => '8655']),
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0003', 'POST /users', $now - 30, 20.0, ['http.request.method' => 'POST', 'http.route' => '/users', 'http.response.status_code' => 201, 'hubhus.customer_id' => '9001']),
        ]]),
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'level' => 'error', 'hubhus_customer_id' => '8655'], 'values' => [[(string) (($now - 20) * 1_000_000_000), 'boom'], [(string) (($now - 25) * 1_000_000_000), 'boom']]],
            ['stream' => ['service_name' => 'billing', 'level' => 'info', 'hubhus_customer_id' => '9001'], 'values' => [[(string) (($now - 10) * 1_000_000_000), 'ok']]],
        ])),
    ];

    Http::fake([...$overrides, ...array_diff_key($defaults, $overrides)]);
}

/**
 * @param  list<array<string, mixed>>  $facets
 * @return array<string, mixed>|null
 */
function facet(array $facets, string $key): ?array
{
    foreach ($facets as $facet) {
        if ($facet['key'] === $key) {
            return $facet;
        }
    }

    return null;
}

it('facets requests on the built-ins plus every declared dimension', function (): void {
    fakeFacetBackends();

    $response = $this->getJson(apiUrl('facets/requests'))
        ->assertOk()
        ->assertJsonPath('signal', 'requests')
        ->assertJsonPath('exact', false)
        ->assertJsonPath('sample', 3);

    $keys = array_column($response->json('facets'), 'key');

    expect($keys)->toContain('http.route', 'http.request.method', 'http.response.status_code', 'hubhus.customer_id')
        ->not->toContain('url.path')          // traces-only built-in
        ->not->toContain('db.query.text');    // no default signal

    expect(facet($response->json('facets'), 'hubhus.customer_id'))->toBe([
        'key' => 'hubhus.customer_id',
        'label' => 'Customer',
        'group' => 'Hubhus',
        'custom' => true,
        'values' => [['value' => '8655', 'count' => 2], ['value' => '9001', 'count' => 1]],
    ]);

    expect(facet($response->json('facets'), 'http.route'))->toMatchArray([
        'label' => 'Route',
        'custom' => false,
        'values' => [['value' => '/orders', 'count' => 2], ['value' => '/users', 'count' => 1]],
    ]);

    // The declared dimension is selected so the sample carries it.
    expect(sentTraceql()[0])->toContain('span.hubhus.customer_id');
});

it('computes only the requested keys, including undeclared ones', function (): void {
    fakeFacetBackends();

    $response = $this->getJson(apiUrl('facets/requests', ['keys' => ['http.request.method', 'hubhus.campaign_id']]))->assertOk();

    expect(array_column($response->json('facets'), 'key'))->toBe(['http.request.method', 'hubhus.campaign_id'])
        ->and($response->json('facets.0.values'))->toBe([['value' => 'GET', 'count' => 2], ['value' => 'POST', 'count' => 1]])
        ->and($response->json('facets.1'))->toMatchArray(['label' => 'hubhus.campaign_id', 'custom' => false, 'values' => []]);

    expect(sentTraceql()[0])->toContain('span.hubhus.campaign_id');
});

it('facets within the active filters', function (): void {
    fakeFacetBackends();

    $this->getJson(apiUrl('facets/requests', ['where' => ['hubhus.customer_id=8655'], 'keys' => ['http.route']]))->assertOk();

    expect(sentTraceql()[0])->toContain('span.hubhus.customer_id = "8655"');
});

it('facets logs on level, service and declared dimensions', function (): void {
    fakeFacetBackends();

    $response = $this->getJson(apiUrl('facets/logs'))->assertOk()->assertJsonPath('sample', 3);

    expect(array_column($response->json('facets'), 'key'))->toBe(['level', 'service.name', 'hubhus.customer_id'])
        ->and(facet($response->json('facets'), 'level')['values'])->toBe([['value' => 'error', 'count' => 2], ['value' => 'info', 'count' => 1]])
        ->and(facet($response->json('facets'), 'service.name')['values'])->toBe([['value' => 'shop', 'count' => 2], ['value' => 'billing', 'count' => 1]])
        // Dotted keys read the snake_cased Loki label.
        ->and(facet($response->json('facets'), 'hubhus.customer_id'))->toMatchArray(['label' => 'Customer', 'custom' => true, 'values' => [['value' => '8655', 'count' => 2], ['value' => '9001', 'count' => 1]]]);
});

it('facets errors on exception, service, user and source', function (): void {
    $now = time();

    fakeFacetBackends([
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'abc123def456', 'exception_type' => 'PaymentDeclined', 'user_id' => '7'], 'values' => [[(string) (($now - 60) * 1_000_000_000), 'e']]],
        ])),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    $response = $this->getJson(apiUrl('facets/errors'))->assertOk();

    expect(array_column($response->json('facets'), 'key'))->toBe(['exception.type', 'service.name', 'user.id', 'source'])
        ->and(facet($response->json('facets'), 'exception.type')['values'])->toBe([['value' => 'PaymentDeclined', 'count' => 1]])
        ->and(facet($response->json('facets'), 'source')['values'])->toBe([['value' => 'backend', 'count' => 1]]);
});

it('answers a typed 502 when the backend fails', function (): void {
    fakeFacetBackends(['tempo.test:3200/*' => Http::response('down', 503)]);

    $this->getJson(apiUrl('facets/traces'))->assertStatus(502)->assertJsonPath('error.type', 'backend');
});
