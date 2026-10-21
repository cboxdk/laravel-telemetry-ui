<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
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

it('says the span fallback timed out instead of claiming there are no edges', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'prometheus.test')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        }

        throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received');
    });

    $graph = $this->getJson(panelUrl('service-graph', ['period' => '1h']))->assertOk()->json();

    expect($graph['error'] ?? null)->toContain('did not answer within')
        ->and($graph['empty'] ?? '')->not->toContain('metrics-generator');
});

it('keeps the client edges and says the database half is missing when only that search fails', function (): void {
    $now = time() - 60;

    Http::fake(function (Request $request) use ($now) {
        if (str_contains($request->url(), 'prometheus.test')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        }

        if (str_contains((string) (requestQuery($request)['q'] ?? ''), 'db.system.name != nil')) {
            return Http::response('query failed', 500);
        }

        return Http::response(['traces' => [
            tempoHit('t1', 'GET', $now, 40.0, ['server.address' => 'api.stripe.com', 'http.response.status_code' => 200], 'checkout'),
        ]]);
    });

    $graph = $this->getJson(panelUrl('service-graph', ['period' => '1h']))->assertOk()->json();

    expect(collect($graph['parts'][0]['edges'])->pluck('target')->all())->toBe(['api.stripe.com'])
        ->and($graph['note'])->toContain('Part of this graph is missing');
});
