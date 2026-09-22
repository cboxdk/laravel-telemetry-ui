<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Explore\Stats;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Illuminate\Support\Facades\Http;

/** Three server spans in the last minute: two on /orders (one failing), one on /users. */
function fakeExploreSpans(): void
{
    $now = time();

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0001', 'GET /orders', $now - 50, 120.5, [
                'http.request.method' => 'GET', 'http.route' => '/orders', 'url.path' => '/orders',
                'http.response.status_code' => 200, 'hubhus.customer_id' => '8655', 'user.id' => '7',
            ]),
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0002', 'GET /orders', $now - 30, 1800.0, [
                'http.request.method' => 'GET', 'http.route' => '/orders', 'url.path' => '/orders',
                'http.response.status_code' => 500, 'hubhus.customer_id' => '8655',
            ]),
            tempoHit('aaaa0000aaaa0000aaaa0000aaaa0003', 'POST /users', $now - 10, 40.0, [
                'http.request.method' => 'POST', 'http.route' => '/users', 'url.path' => '/users',
                'http.response.status_code' => 201, 'hubhus.customer_id' => '9001',
            ]),
        ]]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

it('compiles where[] filters into typed traceql for requests', function (): void {
    fakeExploreSpans();

    $this->getJson(apiUrl('explore/requests', [
        'service' => 'shop',
        'where' => [
            'http.response.status_code>=500',     // numeric → bare token
            'hubhus.customer_id=8655',            // *_id looks numeric but is a string
            'user.id!=7',                         // .id → string too
            'http.route=/orders/{id}',            // string literal, braces intact
            'http.request.method=~GET|POST',      // regex always quoted
            'host.name=web-1',                    // resource attr → unscoped field
            'cache.hit=true',                     // boolean → bare token
            'status=error',                       // intrinsic
            'duration>250',                       // intrinsic, ms default unit
            'db.query.text=',                     // empty value → absent
        ],
        'q' => 'checkout (v2)',
    ]))->assertOk();

    $q = sentTraceql()[0] ?? '';

    expect($q)
        ->toStartWith('{ resource.service.name = "shop" && ')
        ->toContain('span.http.response.status_code >= 500')
        ->toContain('span.hubhus.customer_id = "8655"')
        ->toContain('span.user.id != "7"')
        ->toContain('span.http.route = "/orders/{id}"')
        ->toContain('span.http.request.method =~ "GET|POST"')
        ->toContain('.host.name = "web-1"')
        ->toContain('span.cache.hit = true')
        ->toContain('status = error')
        ->toContain('duration > 250ms')
        ->toContain('span.db.query.text = nil')
        ->toContain('kind = server')                          // requests default
        ->toContain('name =~ ".*checkout \\\\(v2\\\\).*"')      // q → escaped name regex
        ->toContain('| select(')
        ->toContain('span.http.route');
});

it('keeps an explicit kind instead of the requests default', function (): void {
    fakeExploreSpans();

    $this->getJson(apiUrl('explore/requests', ['where' => ['kind=client', 'duration>1.5s']]))->assertOk();

    expect(sentTraceql()[0])->toContain('kind = client')->not->toContain('kind = server')->toContain('duration > 1.5s');
});

it('drops intrinsic filters with values traceql cannot take', function (): void {
    fakeExploreSpans();

    $this->getJson(apiUrl('explore/traces', ['service' => 'shop', 'where' => ['status=broken', 'kind=weird', 'duration>soon']]))->assertOk();

    expect(sentTraceql()[0])->toBe('{ resource.service.name = "shop" } | select('.explode('| select(', sentTraceql()[0])[1]);
});

it('does not default traces to server spans when scoped, but never sends an empty spanset', function (): void {
    fakeExploreSpans();

    $this->getJson(apiUrl('explore/traces', ['service' => 'shop']))->assertOk();
    $this->getJson(apiUrl('explore/traces', ['service' => '']))->assertOk();

    [$scoped, $unscoped] = sentTraceql();

    expect($scoped)->not->toContain('kind =')
        ->and($unscoped)->toStartWith('{ kind = server }');
});

it('returns rows, stats, series and heatmap for requests', function (): void {
    fakeExploreSpans();

    $response = $this->getJson(apiUrl('explore/requests', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('signal', 'requests')
        ->assertJsonPath('sample.size', 3)
        ->assertJsonPath('sample.exact', false)
        ->assertJsonPath('sample.truncated', false)
        ->assertJsonPath('groups', null)
        ->assertJsonPath('where', []);

    // Newest first, request-shaped.
    expect($response->json('rows.0'))->toMatchArray([
        'traceId' => 'aaaa0000aaaa0000aaaa0000aaaa0003',
        'name' => 'POST /users',
        'service' => 'shop',
        'method' => 'POST',
        'route' => '/users',
        'target' => '/users',
        'status' => '201',
        'error' => false,
        'browser' => false,
        'durationMs' => 40,
    ])->and($response->json('rows.0.attributes'))->toMatchArray(['http.route' => '/users', 'http.response.status_code' => '201'])
        // Undeclared attributes aren't carried on rows (declare them, or group by them).
        ->and($response->json('rows.0.attributes'))->not->toHaveKey('hubhus.customer_id');

    expect($response->json('rows.1.error'))->toBeTrue() // the 500
        ->and($response->json('stats'))->toMatchArray(['count' => 3, 'errors' => 1, 'traces' => 3, 'p50' => 120.5, 'p99' => 1800])
        ->and($response->json('series.count'))->toHaveCount(60)
        ->and(array_sum(array_column($response->json('series.count'), 1)))->toBe(3)
        ->and($response->json('heatmap.xs'))->toHaveCount(40)
        ->and($response->json('heatmap.ys'))->toHaveCount(count(Stats::BANDS) + 1)
        ->and(array_sum(array_column($response->json('heatmap.cells'), 2)))->toBe(3);
});

it('flags a span with error status as an error even without a 5xx', function (): void {
    $now = time();

    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => [
        tempoHit('bbbb0000bbbb0000bbbb0000bbbb0001', 'queue.process', $now - 20, 12.0, ['status' => 'error']),
    ]])]);

    $this->getJson(apiUrl('explore/traces', ['where' => ['status=error']]))
        ->assertOk()
        ->assertJsonPath('rows.0.error', true)
        ->assertJsonPath('stats.errors', 1);
});

it('groups the sample by any attribute', function (): void {
    fakeExploreSpans();

    $response = $this->getJson(apiUrl('explore/requests', ['groupBy' => 'hubhus.customer_id']))
        ->assertOk()
        ->assertJsonPath('groupBy', 'hubhus.customer_id')
        ->assertJsonPath('sample.groupsExact', false);

    expect($response->json('groups.0'))->toMatchArray(['value' => '8655', 'count' => 2, 'errors' => 1, 'errorRate' => 0.5])
        ->and($response->json('groups.1'))->toMatchArray(['value' => '9001', 'count' => 1, 'errors' => 0]);

    // The group-by key is selected so undeclared attributes come back too.
    expect(sentTraceql()[0])->toContain('span.hubhus.customer_id');
});

it('echoes the parsed filters and clamps the limit', function (): void {
    fakeExploreSpans();

    $this->getJson(apiUrl('explore/requests', ['where' => ['http.route=/orders', 'not a filter'], 'limit' => '99999']))
        ->assertOk()
        ->assertJsonPath('where', ['http.route=/orders'])
        ->assertJsonPath('sample.limit', 2000);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search') && requestQuery($request)['limit'] === '2000');
});

it('compiles log filters into logql label filters, level and line filters', function (): void {
    $now = time();

    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([
        ['stream' => ['service_name' => 'shop', 'level' => 'error', 'hubhus_customer_id' => '8655', 'trace_id' => 'cccc0000cccc0000cccc0000cccc0001'], 'values' => [
            [(string) (($now - 20) * 1_000_000_000), 'Payment timeout for order 17'],
        ]],
        ['stream' => ['service_name' => 'shop', 'detected_level' => 'warn', 'hubhus_customer_id' => '8655'], 'values' => [
            [(string) (($now - 40) * 1_000_000_000), 'Retrying timeout'],
        ]],
    ]))]);

    $response = $this->getJson(apiUrl('explore/logs', [
        'service' => 'shop',
        'env' => 'prod',
        'where' => ['hubhus.customer_id=8655', 'level=error', 'http-route!~/admin.*'],
        'q' => 'timeout',
    ]))->assertOk();

    expect(sentLogql()[0])->toBe(
        '{service_name="shop"} | deployment_environment_name="prod"'
        .' | hubhus_customer_id="8655"'
        .' | level=~"(?i)error|err|critical|alert|emergency|fatal" or detected_level=~"(?i)error|err|critical|alert|emergency|fatal"'
        .' | http_route!~"/admin.*"'
        .' |= "timeout"'
    );

    expect($response->json('signal'))->toBe('logs')
        ->and($response->json('rows.0'))->toMatchArray([
            'level' => 'error',
            'tone' => 'danger',
            'service' => 'shop',
            'message' => 'Payment timeout for order 17',
            'traceId' => 'cccc0000cccc0000cccc0000cccc0001',
            'labels' => ['hubhus_customer_id' => '8655'],
        ])
        ->and($response->json('rows.1.level'))->toBe('warn')
        ->and($response->json('stats'))->toMatchArray(['count' => 2, 'errors' => 1, 'traces' => 1, 'levels' => ['error' => 1, 'warn' => 1]]);
});

it('negates a level filter to match neither level label', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([]))]);

    $this->getJson(apiUrl('explore/logs', ['where' => ['level!=debug']]))->assertOk();

    expect(sentLogql()[0])->toContain('| level!~"(?i)debug|trace" and detected_level!~"(?i)debug|trace"');
});

it('applies numeric log comparisons read-side', function (): void {
    $now = time();

    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([
        ['stream' => ['service_name' => 'shop', 'level' => 'info', 'duration_ms' => '850'], 'values' => [[(string) (($now - 20) * 1_000_000_000), 'slow']]],
        ['stream' => ['service_name' => 'shop', 'level' => 'info', 'duration_ms' => '12'], 'values' => [[(string) (($now - 30) * 1_000_000_000), 'fast']]],
    ]))]);

    $this->getJson(apiUrl('explore/logs', ['where' => ['duration.ms>100'], 'groupBy' => 'level']))
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.message', 'slow')
        ->assertJsonPath('groups.0.value', 'info');

    // LogQL has no numeric op in the IR, so nothing numeric is sent.
    expect(sentLogql()[0])->not->toContain('duration_ms');
});

it('groups backend exception records and browser exception spans by fingerprint', function (): void {
    $now = time();
    $browserGroup = ExceptionFingerprint::compute('TypeError', 'https://app.test/build/app.js', 42);

    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'abc123def456', 'exception_type' => 'App\\Exceptions\\PaymentDeclined', 'exception_message' => 'Card declined', 'user_id' => '7', 'trace_id' => 'dddd0000dddd0000dddd0000dddd0001'], 'values' => [
                [(string) (($now - 300) * 1_000_000_000), 'exception'],
                [(string) (($now - 60) * 1_000_000_000), 'exception'],
            ]],
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'abc123def456', 'exception_type' => 'App\\Exceptions\\PaymentDeclined', 'exception_message' => 'Card declined', 'user_id' => '9'], 'values' => [
                [(string) (($now - 30) * 1_000_000_000), 'exception'],
            ]],
            // The browser error was also reported by the backend under the same fingerprint.
            ['stream' => ['service_name' => 'shop', 'exception_group' => $browserGroup, 'exception_type' => 'TypeError', 'exception_message' => 'x is undefined'], 'values' => [
                [(string) (($now - 200) * 1_000_000_000), 'exception'],
            ]],
        ])),
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            tempoHit('eeee0000eeee0000eeee0000eeee0001', 'exception', $now - 20, 0.0, [
                'browser' => true, 'exception.type' => 'TypeError', 'exception.message' => 'x is undefined',
                'exception.file' => 'https://app.test/build/app.js', 'exception.line' => 42, 'user.id' => '11',
            ], service: 'shop-web'),
        ]]),
    ]);

    $response = $this->getJson(apiUrl('explore/errors'))->assertOk()->assertJsonPath('signal', 'errors');

    expect($response->json('rows.0'))->toMatchArray([
        'group' => 'abc123def456',
        'type' => 'App\\Exceptions\\PaymentDeclined',
        'message' => 'Card declined',
        'count' => 3,
        'users' => 2,
        'services' => ['shop'],
        'source' => 'backend',
    ])->and($response->json('rows.1'))->toMatchArray([
        'group' => $browserGroup,
        'type' => 'TypeError',
        'count' => 2,
        'source' => 'full-stack',
        'traceId' => 'eeee0000eeee0000eeee0000eeee0001',
    ])->and($response->json('stats'))->toMatchArray(['count' => 5, 'groups' => 2, 'users' => 3, 'frontend' => 1])
        ->and(array_sum($response->json('rows.0.spark')))->toBe(3);

    expect(sentLogql()[0])->toContain('| exception_group!=""')
        ->and(sentTraceql()[0])->toContain('span.browser = true')->toContain('span.exception.type != nil');
});

it('filters errors by source and free text', function (): void {
    $now = time();

    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'abc123def456', 'exception_type' => 'PaymentDeclined', 'exception_message' => 'Card declined'], 'values' => [[(string) (($now - 60) * 1_000_000_000), 'e']]],
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'fff000fff000', 'exception_type' => 'QueryException', 'exception_message' => 'Deadlock'], 'values' => [[(string) (($now - 50) * 1_000_000_000), 'e']]],
        ])),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    $this->getJson(apiUrl('explore/errors', ['where' => ['source=backend'], 'q' => 'deadlock']))
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.type', 'QueryException');

    // source=backend skips the browser-span search entirely.
    expect(sentTraceql())->toBe([]);
});

it('answers a typed 502 when the backend fails', function (string $signal, string $host): void {
    Http::fake([$host.'/*' => Http::response('upstream down', 503)]);

    $this->getJson(apiUrl('explore/'.$signal))
        ->assertStatus(502)
        ->assertJsonPath('error.type', 'backend')
        ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, '503'));
})->with([
    ['requests', 'tempo.test:3200'],
    ['traces', 'tempo.test:3200'],
    ['logs', 'loki.test:3100'],
    ['errors', 'loki.test:3100'],
]);

it('404s an unknown signal at the route', function (): void {
    $this->getJson(apiUrl('explore/metrics'))->assertNotFound();
});
