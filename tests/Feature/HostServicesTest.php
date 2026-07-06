<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

// Own file, own fakes: the host-services scenarios need per-query responses,
// and a shared beforeEach's broad `query*` stub would win the fake-order race.
beforeEach(fn () => Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true));

function fakeHostExporters(): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            // redis_exporter answers for this host; every other probe is empty.
            if (str_contains($q, 'redis_up')) {
                return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
                    ['metric' => ['instance' => 'web-3:9121'], 'value' => [1735689600, '1']],
                ]]]);
            }

            if (str_contains($q, 'redis_memory_used_bytes')) {
                return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
                    ['metric' => [], 'value' => [1735689600, '52428800']],
                ]]]);
            }

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        },
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
}

it('shows exporter-backed services on the host detail page', function (): void {
    fakeHostExporters();

    $this->get('/telemetry-ui/host-detail?host=web-3')
        ->assertOk()
        ->assertSee('Redis')
        ->assertSee('50 MB')
        ->assertDontSee('MySQL'); // probe returned nothing → section hidden

    // The {host} token expanded into the exporter matcher.
    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'redis_up{instance=~"web-3(:.*)?"}'));
});

it('shows the empty state when no exporters answer for the host', function (): void {
    Http::fake([
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->get('/telemetry-ui/host-detail?host=web-3')
        ->assertOk()
        ->assertSee('No service exporters detected');
});
