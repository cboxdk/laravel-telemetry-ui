<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

it('renders a purpose-built request detail page scoped to the route', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

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

    $this->get('/telemetry-ui/request-detail?route=/orders')
        ->assertOk()
        ->assertSee('/orders')
        ->assertSee('All requests');

    // Every metric on the page is scoped to the one route.
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'http_route="/orders"'));
});

it('keeps the request detail page out of the sidebar nav', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    Http::fake([
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    // The nav lists "Requests" but never a "Request" detail entry.
    $this->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertDontSeeHtml('>Request</a>');
});
