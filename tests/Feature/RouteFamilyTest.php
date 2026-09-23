<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    TelemetryUi::routeFamily('hubhus', label: 'Screens', pattern: 'hubhus:{value}', dimension: 'hubhus.screen');

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['http_route' => 'hubhus:checkout', 'http_request_method' => 'POST', 'http_response_status_code' => '200'], 'value' => [1735689600, '40']],
        ]]]),
    ]);
});

it('registers the family as a page with its own panels', function (): void {
    $this->getJson(apiUrl('pages/hubhus'))
        ->assertOk()
        ->assertJsonPath('label', 'Screens')
        ->assertJsonPath('panels.*.id', ['family-activity', 'family-table']);
});

it('narrows the table to the family and strips the prefix, linking each value to its page', function (): void {
    $this->getJson(panelUrl('family-table', ['_page' => 'hubhus']))
        ->assertOk()
        ->assertJsonPath('title', 'Screens')
        ->assertJsonPath('columns.1.label', 'Screen')
        ->assertJsonPath('rows.0.route.v', 'checkout')
        ->assertJsonPath('rows.0.route.dim', ['key' => 'hubhus.screen', 'value' => 'checkout'])
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'hubhus.screen', 'value' => 'checkout']);

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return ! str_contains($q, 'http_server_request') || str_contains($q, 'http_route=~"^hubhus:.+$"');
    });
});

it('declares the dimension derived, so the values work everywhere else too', function (): void {
    $screen = collect($this->getJson(apiUrl('bootstrap'))->json('dimensions'))->firstWhere('key', 'hubhus.screen');

    expect($screen)->toMatchArray([
        'label' => 'Screen',
        'plural' => 'Screens',
        'group' => 'Screens',
        'derivedFrom' => 'http.route',
        'builtin' => false,
    ]);
});

it('leaves the built-in routes table alone', function (): void {
    $this->getJson(panelUrl('routes-table'))
        ->assertOk()
        ->assertJsonPath('columns.1.label', 'Route')
        ->assertJsonPath('rows.0.route.v', 'hubhus:checkout')
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'route', 'value' => 'hubhus:checkout']);
});
