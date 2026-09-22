<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('charts db throughput and headline tiles from db_* counters', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '120'], [1735689660, '150'], [1735689720, '90']]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => [], 'value' => [1735689600, '4200']],
            ]],
        ]),
    ]);

    $this->getJson(panelUrl('query-throughput'))
        ->assertOk()
        ->assertJsonPath('kind', 'chart')
        ->assertJsonPath('title', 'Database throughput')
        ->assertJsonFragment(['label' => 'Queries', 'value' => '4.2K']); // total queries in the period
});
