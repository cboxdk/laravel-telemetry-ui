<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

// Own file, own fakes: the paths aggregation needs a specific Tempo response.
it('aggregates concrete paths behind a route from its own spans', function (): void {
    $now = time();

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /packages/{slug}', 'startTimeUnixNano' => (string) (($now - 60) * 1_000_000_000), 'durationMs' => 80,
                    'spanSets' => [['spans' => [
                        ['spanID' => 'p1', 'name' => 'GET /packages/{slug}', 'startTimeUnixNano' => (string) (($now - 60) * 1_000_000_000), 'durationNanos' => '80000000', 'attributes' => [
                            ['key' => 'url.path', 'value' => ['stringValue' => '/packages/laravel-telemetry']],
                            ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                        ]],
                        ['spanID' => 'p2', 'name' => 'GET /packages/{slug}', 'startTimeUnixNano' => (string) (($now - 50) * 1_000_000_000), 'durationNanos' => '40000000', 'attributes' => [
                            ['key' => 'url.path', 'value' => ['stringValue' => '/packages/does-not-exist']],
                            ['key' => 'http.response.status_code', 'value' => ['intValue' => '404']],
                        ]],
                    ]]]],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $response = $this->getJson(panelUrl('request-detail-paths', ['route' => '/packages/{slug}']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertSee('/packages/laravel-telemetry', false)
        ->assertSee('/packages/does-not-exist', false);

    // Rows tail the path in the request log; ⇄ opens the newest request.
    $rows = collect($response->json('rows'))->keyBy('path.v');
    expect($rows['/packages/does-not-exist']['_link'])->toBe(['to' => 'page', 'page' => 'requests', 'params' => ['log_path' => '/packages/does-not-exist']])
        ->and($rows['/packages/does-not-exist']['errors']['tone'])->toBe('danger')
        ->and($rows['/packages/laravel-telemetry']['latest']['link'])->toBe(['to' => 'trace', 'id' => 'aaaa1111aaaa1111aaaa1111aaaa1111']);

    // The span search carries the route scope — no backend-wide path leak.
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.url.path') && str_contains($q, '/packages/{slug}');
    });
});
