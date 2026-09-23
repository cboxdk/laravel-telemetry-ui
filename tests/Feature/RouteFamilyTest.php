<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    TelemetryUi::routeFamily('portal', label: 'Screens', pattern: 'portal:{value}', dimension: 'portal.screen');

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['http_route' => 'portal:checkout', 'http_request_method' => 'POST', 'http_response_status_code' => '200'], 'value' => [1735689600, '40']],
        ]]]),
    ]);
});

it('registers the family as a page with its own panels', function (): void {
    $this->getJson(apiUrl('pages/portal'))
        ->assertOk()
        ->assertJsonPath('label', 'Screens')
        ->assertJsonPath('panels.*.id', ['family-activity', 'family-table']);
});

it('narrows the table to the family and strips the prefix, linking each value to its page', function (): void {
    $this->getJson(panelUrl('family-table', ['_page' => 'portal']))
        ->assertOk()
        ->assertJsonPath('title', 'Screens')
        ->assertJsonPath('columns.1.label', 'Screen')
        ->assertJsonPath('rows.0.route.v', 'checkout')
        ->assertJsonPath('rows.0.route.dim', ['key' => 'portal.screen', 'value' => 'checkout'])
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'portal.screen', 'value' => 'checkout']);

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return ! str_contains($q, 'http_server_request') || str_contains($q, 'http_route=~"^portal:.+$"');
    });
});

it('declares the dimension derived, so the values work everywhere else too', function (): void {
    $screen = collect($this->getJson(apiUrl('bootstrap'))->json('dimensions'))->firstWhere('key', 'portal.screen');

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
        ->assertJsonPath('rows.0.route.v', 'portal:checkout')
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'route', 'value' => 'portal:checkout']);
});
