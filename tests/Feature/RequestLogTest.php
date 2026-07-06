<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\RequestLog;
use Cbox\TelemetryUi\Cards\Builtin\RoutesTable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeRequestLog(): void
{
    $now = time();

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '1111111111111111aaaaaaaaaaaaaaaa', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /orders', 'startTimeUnixNano' => (string) (($now - 5) * 1_000_000_000), 'durationMs' => 120,
                    'spanSets' => [['spans' => [['spanID' => 'a1', 'name' => 'GET /orders', 'startTimeUnixNano' => (string) (($now - 5) * 1_000_000_000), 'durationNanos' => '120000000', 'attributes' => [
                        ['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']],
                        ['key' => 'url.path', 'value' => ['stringValue' => '/orders']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                        ['key' => 'client.address', 'value' => ['stringValue' => '203.0.113.9']],
                        ['key' => 'enduser.id', 'value' => ['intValue' => '25']],
                    ]]]]]],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
}

it('stays hidden while the routes view is active', function (): void {
    fakeRequestLog();

    Livewire::test(RequestLog::class)
        ->assertDontSee('Request log');

    Http::assertNothingSent(); // hidden mode costs zero backend queries
});

it('lists individual requests with user, ip and status', function (): void {
    fakeRequestLog();

    Livewire::withQueryParams(['req_view' => 'log'])
        ->test(RequestLog::class)
        ->assertSee('/orders')
        ->assertSee('#25')
        ->assertSee('203.0.113.9')
        ->assertSee('200')
        ->assertSeeHtml('data-row-trace="1111111111111111aaaaaaaaaaaaaaaa"');
});

it('tails a specific user and ip via scoped traceql', function (): void {
    fakeRequestLog();

    Livewire::withQueryParams(['req_view' => 'log'])
        ->test(RequestLog::class)
        ->set('user', '25')
        ->set('ip', '203.0.113.9')
        ->set('statusCode', '5xx');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.enduser.id = "25"')
            && str_contains($q, 'span.client.address = "203.0.113.9"')
            && str_contains($q, 'span.http.response.status_code >= 500');
    });
});

it('live-tails by default', function (): void {
    fakeRequestLog();

    $html = Livewire::withQueryParams(['req_view' => 'log'])
        ->test(RequestLog::class)
        ->html();

    expect($html)->toContain('wire:poll.4s')
        ->and($html)->toContain('● Live');
});

it('flips between routes and log via a livewire event, not a page load', function (): void {
    fakeRequestLog();

    // The log card appears when the toggle event fires…
    Livewire::test(RequestLog::class)
        ->assertDontSee('Request log')
        ->dispatch('telemetry-ui:request-view-changed', view: 'log')
        ->assertSee('Request log');

    // …and the routes card yields on the same event, and comes back.
    Livewire::test(RoutesTable::class)
        ->assertSee('Search routes')
        ->dispatch('telemetry-ui:request-view-changed', view: 'log')
        ->assertDontSee('Search routes')
        ->dispatch('telemetry-ui:request-view-changed', view: 'routes')
        ->assertSee('Search routes');
});

it('polls when live tailing is on', function (): void {
    fakeRequestLog();

    $html = Livewire::withQueryParams(['req_view' => 'log', 'live' => '1'])
        ->test(RequestLog::class)
        ->html();

    expect($html)->toContain('wire:poll.4s')
        ->and($html)->toContain('● Live');
});

it('the routes card yields to the log view', function (): void {
    fakeRequestLog();

    Livewire::withQueryParams(['req_view' => 'log'])
        ->test(RoutesTable::class)
        ->assertDontSee('Search routes');
});

it('the routes card links to the request log', function (): void {
    fakeRequestLog();

    Livewire::test(RoutesTable::class)
        ->assertSee('Routes')
        ->assertSee('Request log'); // the toggle link
});
