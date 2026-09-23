<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

function labelledVector(array $series): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(
        static fn (array $s): array => ['metric' => $s[0], 'value' => [1735689600, (string) $s[1]]],
        $series,
    )]];
}

it('breaks cache operations down per store with a hit ratio', function (): void {
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(labelledVector([
        [['store' => 'redis', 'operation' => 'hit'], 900],
        [['store' => 'redis', 'operation' => 'miss'], 100],
        [['store' => 'redis', 'operation' => 'write'], 120],
        [['store' => 'file', 'operation' => 'hit'], 10],
        [['store' => 'file', 'operation' => 'miss'], 30],
    ]))]);

    $this->getJson(panelUrl('cache-by-store'))
        ->assertOk()
        ->assertJsonPath('span', 2)
        ->assertJsonPath('rows.0.store.v', 'redis')
        ->assertJsonPath('rows.0.ratio.v', '90%')
        ->assertJsonPath('rows.0.ratio.tone', 'ok')
        ->assertJsonPath('rows.1.store.v', 'file')
        ->assertJsonPath('rows.1.ratio.tone', 'warn')
        ->assertJsonPath('rows.1.miss.tone', 'warn');
});

it('breaks storage operations down per disk and operation', function (): void {
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(labelledVector([
        [['disk' => 's3', 'operation' => 'put'], 30],
        [['disk' => 'local', 'operation' => 'get'], 10],
    ]))]);

    $this->getJson(panelUrl('storage-by-disk'))
        ->assertOk()
        ->assertJsonPath('rows.0.k0.v', 's3')
        ->assertJsonPath('rows.0.k1.v', 'put')
        ->assertJsonPath('rows.0.share.v', '75%');
});
