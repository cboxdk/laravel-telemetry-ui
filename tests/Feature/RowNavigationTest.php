<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\RoutesTable;
use Cbox\TelemetryUi\Cards\Builtin\TraceSearch;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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

    Livewire::test(RoutesTable::class)
        ->assertSee('/orders')
        // The whole row is a click target to the route's traces, not just the link.
        ->assertSeeHtml('data-row-href');
});

it('emits a whole-row drawer trigger on the trace search table', function (): void {
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

    Livewire::test(TraceSearch::class)
        ->assertSee('POST /orders')
        ->assertSeeHtml('data-row-trace="0af7651916cd43dd8448eb211c80319c"');
});
