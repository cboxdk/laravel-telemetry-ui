<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

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
                        ['key' => 'user.id', 'value' => ['intValue' => '25']],
                    ]]]]]],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
}

it('lists individual requests with user, ip and status', function (): void {
    fakeRequestLog();

    $this->getJson(panelUrl('request-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonPath('title', 'Request log')
        ->assertJsonPath('rows.0.request.v', 'GET /orders')
        ->assertJsonPath('rows.0.user.v', '#25')
        ->assertJsonPath('rows.0.ip.v', '203.0.113.9')
        ->assertJsonPath('rows.0.status.v', '200')
        ->assertJsonPath('rows.0.status.tone', 'ok')
        // Every row opens the request's story, and says when it started so the
        // trace store is asked about that stretch of time only.
        ->assertJsonPath('rows.0._link.to', 'trace')
        ->assertJsonPath('rows.0._link.id', '1111111111111111aaaaaaaaaaaaaaaa')
        ->assertJsonPath('rows.0._link.at', fn (mixed $at): bool => is_int($at) && $at > 0)
        // Clicking a user / IP tails them: sets the panel's own filter.
        ->assertJsonPath('rows.0.user.link', ['to' => 'param', 'params' => ['log_user' => '25']])
        ->assertJsonPath('rows.0.ip.link', ['to' => 'param', 'params' => ['log_ip' => '203.0.113.9']]);
});

it('tails a specific user and ip via scoped traceql', function (): void {
    fakeRequestLog();

    $this->getJson(panelUrl('request-log', ['log_user' => '25', 'log_ip' => '203.0.113.9', 'log_status' => '5xx']))
        ->assertOk()
        // The filter controls reflect the bound params.
        ->assertJsonPath('controls.0.param', 'log_user')
        ->assertJsonPath('controls.0.value', '25')
        ->assertJsonPath('controls.3.value', '5xx');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.user.id = "25"')
            && str_contains($q, 'span.client.address = "203.0.113.9"')
            && str_contains($q, 'span.http.response.status_code >= 500');
    });
});

it('offers a live-tail stream carrying the current filters', function (): void {
    fakeRequestLog();

    $this->getJson(panelUrl('request-log', ['log_user' => '25']))
        ->assertOk()
        ->assertJsonPath('stream.signal', 'requests')
        ->assertJsonPath('stream.params', ['panel' => 'request-log', 'log_user' => '25']);
});

it('renders both the routes table and the request log — no view toggle', function (): void {
    fakeRequestLog();

    // v2 dropped the req_view mode swap: both panels always render.
    $this->getJson(panelUrl('request-log', ['req_view' => 'routes']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonPath('title', 'Request log');

    $this->getJson(panelUrl('routes-table', ['req_view' => 'log']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonPath('title', 'Routes')
        ->assertJsonPath('controls.0.param', 'route_search');
});

it('shows the livewire component instead of the anonymous update url', function (): void {
    $now = time();

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '2222222222222222bbbbbbbbbbbbbbbb', 'rootServiceName' => 'demo', 'rootTraceName' => 'POST livewire:trace-drawer', 'startTimeUnixNano' => (string) (($now - 5) * 1_000_000_000), 'durationMs' => 80,
                    'spanSets' => [['spans' => [['spanID' => 'b1', 'name' => 'POST livewire:trace-drawer', 'startTimeUnixNano' => (string) (($now - 5) * 1_000_000_000), 'durationNanos' => '80000000', 'attributes' => [
                        ['key' => 'http.request.method', 'value' => ['stringValue' => 'POST']],
                        ['key' => 'url.path', 'value' => ['stringValue' => '/livewire/update']],
                        ['key' => 'http.route', 'value' => ['stringValue' => 'livewire:trace-drawer']],
                        ['key' => 'livewire.components', 'value' => ['stringValue' => 'trace-drawer']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                    ]]]]]],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->getJson(panelUrl('request-log'))
        ->assertOk()
        ->assertJsonPath('rows.0.request.v', 'POST livewire:trace-drawer')
        ->assertDontSee('/livewire/update', false);
});

it('lists livewire components as a scoped routes table', function (): void {
    fakeRequestLog();

    $this->getJson(panelUrl('livewire-components'))
        ->assertOk()
        ->assertJsonPath('title', 'Components');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'http_route=~"livewire:.*"');
    });
});

it('drills each route row into its detail page', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['http_route' => '/orders', 'http_request_method' => 'GET', 'http_response_status_code' => '500'], 'value' => [1735689600, '12']],
        ]]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->getJson(panelUrl('routes-table'))
        ->assertOk()
        ->assertJsonPath('rows.0.route.v', '/orders')
        ->assertJsonPath('rows.0.5xx.tone', 'danger')
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'route', 'value' => '/orders']);
});

it('narrows the livewire request log to livewire routes', function (): void {
    fakeRequestLog();

    $this->getJson(panelUrl('livewire-request-log'))
        ->assertOk()
        ->assertJsonPath('title', 'Request log')
        ->assertJsonPath('stream.params.panel', 'livewire-request-log');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.http.route =~ "livewire:.*"');
    });
});

it('puts routes with server errors first on the dashboard short list', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['http_route' => '/busy', 'http_request_method' => 'GET', 'http_response_status_code' => '200'], 'value' => [1735689600, '900']],
            ['metric' => ['http_route' => '/broken', 'http_request_method' => 'POST', 'http_response_status_code' => '500'], 'value' => [1735689600, '3']],
        ]]]),
    ]);

    $this->getJson(panelUrl('routes-needing-attention', ['_page' => 'dashboard']))
        ->assertOk()
        ->assertJsonPath('title', 'Routes needing attention')
        ->assertJsonPath('rows.0.route.v', '/broken')
        ->assertJsonPath('drill.page', 'requests')
        ->assertJsonMissingPath('controls');
});
