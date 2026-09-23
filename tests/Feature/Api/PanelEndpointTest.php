<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('serves a chart panel as typed JSON', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['service_name' => 'shop'], 'values' => [[1700000000, '3'], [1700000060, '5']]],
        ]]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->getJson(panelUrl('request-duration', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('id', 'request-duration')
        ->assertJsonPath('kind', 'chart');
});

it('404s an unknown panel with a typed error', function (): void {
    $this->getJson(panelUrl('nope'))
        ->assertNotFound()
        ->assertJsonPath('error.type', 'not_found');
});
