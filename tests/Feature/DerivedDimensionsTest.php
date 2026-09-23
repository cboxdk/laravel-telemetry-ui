<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Dimensions\Derivation;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

function fakeScreenSpans(): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0001', 'hubhus:checkout', time() - 30, 20.0, [
                'http.route' => 'hubhus:checkout', 'http.request.method' => 'POST', 'http.response.status_code' => 200,
            ]),
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0002', 'GET /orders', time() - 20, 10.0, [
                'http.route' => '/orders', 'http.request.method' => 'GET', 'http.response.status_code' => 200,
            ]),
        ]]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

beforeEach(function (): void {
    // "The screen is what follows `hubhus:` in the route" — a routing layer
    // that names requests, the way Livewire does.
    TelemetryUi::dimension('hubhus.screen', label: 'Screen', group: 'Hubhus', from: 'http.route', pattern: 'hubhus:{value}');
});

it('reads a value out of its source attribute, both ways', function (): void {
    $derived = new Derivation('http.route', 'hubhus:{value}');

    expect($derived->encode('checkout'))->toBe('hubhus:checkout')
        ->and($derived->extract('hubhus:checkout'))->toBe('checkout')
        ->and($derived->extract('livewire:cart'))->toBeNull()
        ->and($derived->extract('hubhus:'))->toBeNull()
        ->and($derived->regex())->toBe('^hubhus:.+$')
        ->and((new Derivation('name', 'screen.{value}(v2)'))->regex('x'))->toBe('^screen\\.x\\(v2\\)$');
});

it('rejects a pattern without a placeholder', function (): void {
    new Derivation('http.route', 'hubhus:');
})->throws(InvalidArgumentException::class);

it('asks the backend about the source attribute, exactly', function (): void {
    fakeScreenSpans();

    $this->getJson(apiUrl('explore/requests', ['where' => ['hubhus.screen=checkout']]))->assertOk();

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.http.route = "hubhus:checkout"');
    });
});

it('turns "any screen" and a regex into one query on the source', function (): void {
    fakeScreenSpans();

    $this->getJson(apiUrl('explore/requests', ['where' => ['hubhus.screen!=']]))->assertOk();
    $this->getJson(apiUrl('explore/requests', ['where' => ['hubhus.screen=~check.*']]))->assertOk();

    $queries = collect(Http::recorded())->map(fn ($pair): string => rawurldecode(requestQuery($pair[0])['q'] ?? ''));

    expect($queries->contains(fn (string $q): bool => str_contains($q, 'span.http.route =~ "^hubhus:.+$"')))->toBeTrue()
        ->and($queries->contains(fn (string $q): bool => str_contains($q, 'span.http.route =~ "^hubhus:(?:check.*)$"')))->toBeTrue();
});

it('shows up as a value on rows and as a sampled facet', function (): void {
    fakeScreenSpans();

    $rows = $this->getJson(apiUrl('explore/requests'))->assertOk()->json('rows');

    $screens = collect($rows)->keyBy(fn (array $row): string => $row['attributes']['http.route']);

    expect($screens['hubhus:checkout']['attributes']['hubhus.screen'])->toBe('checkout')
        ->and($screens['/orders']['attributes'])->not->toHaveKey('hubhus.screen');

    $facets = $this->getJson(apiUrl('facets/requests', ['keys' => ['hubhus.screen']]))->assertOk();

    expect($facets->json('facets.0.values'))->toBe([['value' => 'checkout', 'count' => 1]])
        ->and($facets->json('facets.0.custom'))->toBeTrue()
        ->and($facets->json('exact'))->toBeFalse();
});

it('rides along on log lines and their filters', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'level' => 'info', 'http_route' => 'hubhus:checkout'], 'values' => [
                [(string) (time() * 1_000_000_000), 'rendered'],
            ]],
        ])),
        '*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $rows = $this->getJson(apiUrl('explore/logs', ['where' => ['hubhus.screen=checkout']]))->assertOk()->json('rows');

    expect($rows[0]['labels']['hubhus_screen'])->toBe('checkout');

    Http::assertSent(function ($request): bool {
        $query = rawurldecode(requestQuery($request)['query'] ?? '');

        return ! str_contains($query, 'hubhus_screen') || str_contains($query, 'http_route="hubhus:checkout"');
    });
});
