<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('emits a whole-row drill-down link on the routes table', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['http_route' => '/orders', 'http_request_method' => 'GET', 'class' => '2xx'], 'value' => [1735689600, '10']],
            ]],
        ]),
    ]);

    $this->getJson(panelUrl('routes-table'))
        ->assertOk()
        ->assertSee('/orders')
        // The whole row is a click target to the route's detail page, not just the link.
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'route', 'value' => '/orders']);
});

it('emits a whole-row trace link on the trace search table', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [[
                'traceID' => '0af7651916cd43dd8448eb211c80319c',
                'rootServiceName' => 'checkout',
                'rootTraceName' => 'POST /orders',
                'startTimeUnixNano' => '1735689600000000000',
                'durationMs' => 812,
            ]],
        ]),
    ]);

    $this->getJson(panelUrl('trace-search'))
        ->assertOk()
        ->assertSee('POST /orders')
        ->assertJsonPath('rows.0._link', ['to' => 'trace', 'id' => '0af7651916cd43dd8448eb211c80319c', 'at' => 1735689600000]);
});
