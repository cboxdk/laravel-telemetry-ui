<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

it('renders a purpose-built request detail header scoped to the route', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1735689600, '5']]]],
        ]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    $this->getJson(panelUrl('request-detail-header', ['route' => '/orders']))
        ->assertOk()
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', '/orders')
        ->assertJsonPath('stats.0.label', 'Requests')
        ->assertJsonPath('stats.0.value', '5')
        ->assertJsonPath('back', ['to' => 'page', 'page' => 'requests'])
        ->assertJsonPath('backLabel', '← All requests');

    // Every metric on the header is scoped to the one route.
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'http_route="/orders"'));
});

it('lists the recent traces of the route, each opening its trace', function (): void {
    $now = time();

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            ['traceID' => 'abcdabcdabcdabcdabcdabcdabcdabcd', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /orders', 'startTimeUnixNano' => (string) (($now - 30) * 1_000_000_000), 'durationMs' => 1500],
        ]]),
    ]);

    $this->getJson(panelUrl('request-detail-traces', ['route' => '/orders']))
        ->assertOk()
        ->assertJsonPath('title', 'Recent traces')
        ->assertJsonPath('rows.0.trace.v', 'GET /orders')
        ->assertJsonPath('rows.0.duration.tone', 'warn')
        ->assertJsonPath('rows.0._link', ['to' => 'trace', 'id' => 'abcdabcdabcdabcdabcdabcdabcdabcd']);

    Http::assertSent(fn ($request): bool => str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'span.http.route = "/orders"'));
});

it('keeps the request detail page out of the sidebar nav', function (): void {
    $pages = app(TelemetryUiManager::class)->pages();

    // Routable (reached by drilling a row) but hidden from the nav.
    expect($pages['request-detail']['hidden'] ?? false)->toBeTrue()
        ->and($pages['requests']['hidden'] ?? false)->toBeFalse();

    $this->getJson(apiUrl('pages/request-detail'))
        ->assertOk()
        ->assertJsonPath('panels.0.id', 'request-detail-header');
});
