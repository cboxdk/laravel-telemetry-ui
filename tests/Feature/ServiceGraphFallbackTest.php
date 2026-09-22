<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('derives the service graph from client and database spans when there are no service-graph metrics', function (): void {
    $now = time() - 60;

    Http::fake(function (Request $request) use ($now) {
        if (str_contains($request->url(), 'prometheus.test')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        }

        $q = (string) (requestQuery($request)['q'] ?? '');

        return Http::response(['traces' => str_contains($q, 'db.system.name != nil')
            ? [tempoHit('t2', 'db.query', $now, 1.5, ['db.system.name' => 'mysql'], 'checkout')]
            : [
                tempoHit('t1', 'GET', $now, 40.0, ['server.address' => 'api.stripe.com', 'http.response.status_code' => 502], 'checkout'),
                tempoHit('t3', 'GET', $now, 20.0, ['server.address' => 'api.stripe.com', 'http.response.status_code' => 200], 'checkout'),
            ]]);
    });

    $graph = $this->getJson(panelUrl('service-graph', ['period' => '1h']))->assertOk()->json();

    $edges = collect($graph['parts'][0]['edges'])->keyBy('target');

    expect($edges->keys()->all())->toEqualCanonicalizing(['api.stripe.com', 'db:mysql'])
        ->and($edges['api.stripe.com']['count'])->toEqual(2)
        ->and($edges['api.stripe.com']['errors'])->toEqual(1)
        ->and($graph['note'])->toContain('Derived from 3 sampled client spans');
});
