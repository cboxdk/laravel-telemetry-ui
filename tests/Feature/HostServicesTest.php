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

    $this->getJson(panelUrl('host-services', ['host' => 'web-3']))
        ->assertOk()
        ->assertJsonPath('kind', 'composite')
        ->assertJsonCount(1, 'parts') // only the exporter that answered
        ->assertJsonPath('parts.0.title', 'Redis')
        ->assertJsonPath('parts.0.badge.label', 'up')
        ->assertJsonPath('parts.0.badge.tone', 'ok')
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

    $this->getJson(panelUrl('host-services', ['host' => 'web-3']))
        ->assertOk()
        ->assertJsonPath('parts', [])
        ->assertSee('No service exporters detected');
});

it('lists hosts linking to the host page and to their requests', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            $value = match (true) {
                str_contains($q, 'memory') => '0.95',
                str_contains($q, 'cpu') => '0.4',
                str_contains($q, '5..') => '3',
                default => '1200',
            };

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['host_name' => 'web-3'], 'value' => [1735689600, $value]],
            ]]]);
        },
    ]);

    $this->getJson(panelUrl('hosts-table'))
        ->assertOk()
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'host', 'value' => 'web-3'])
        ->assertJsonPath('rows.0.host.dim', ['key' => 'host.name', 'value' => 'web-3'])
        ->assertJsonPath('rows.0.requests.link.page', 'traces')
        ->assertJsonPath('rows.0.requests.link.params.q', fn (string $q): bool => str_contains($q, '.host.name = "web-3"'))
        ->assertJsonPath('rows.0.errors.tone', 'danger')
        ->assertJsonPath('rows.0.memory.tone', 'warn');
});

it('renders the host detail header with a back link', function (): void {
    fakeHostExporters();

    $this->getJson(panelUrl('host-detail-header', ['host' => 'web-3']))
        ->assertOk()
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', 'web-3')
        ->assertJsonPath('back.page', 'hosts')
        ->assertJsonPath('stats.0.label', 'CPU');
});
