<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/*
 * The v1 Livewire drawer is client-side in v2; these cover the endpoints it
 * reads: the trace story, an error group, a tracker issue, and ticket creation.
 */

// Creating tickets requires the write ability; allow it by default so the
// compose tests exercise the happy path (a dedicated test revokes it).
beforeEach(fn () => Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true));

const DRAWER_TRACE = 'abc123abc123abc123abc123abc123ab';

/**
 * A two-span Tempo trace (server request + db query) and quiet correlation
 * backends. The first matching fake wins, so overrides go in front.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeTrace(array $overrides = []): void
{
    $defaults = [
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                    ['key' => 'host.name', 'value' => ['stringValue' => 'web-3']],
                ]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000', 'attributes' => [
                        ['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']],
                        ['key' => 'http.route', 'value' => ['stringValue' => '/orders']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                        ['key' => 'billing.customer_id', 'value' => ['stringValue' => '8655']],
                    ]],
                    ['spanId' => 'a2', 'parentSpanId' => 'a1', 'name' => 'db.query', 'kind' => 3, 'startTimeUnixNano' => '1735689600200000000', 'endTimeUnixNano' => '1735689600400000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from orders']],
                    ]],
                ]]],
            ]],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['service_name' => 'checkout', 'level' => 'info', 'trace_id' => DRAWER_TRACE], 'values' => [['1735689600300000000', 'Order list rendered']]],
        ]]]),
    ];

    Http::fake([...$overrides, ...array_diff_key($defaults, $overrides)]);
}

function githubIssues(): void
{
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_test',
    ]);
}

it('serves the whole trace story in one payload', function (): void {
    fakeTrace();

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('traceId', DRAWER_TRACE)
        ->assertJsonPath('root.name', 'GET /orders')
        ->assertJsonPath('root.service', 'checkout')
        ->assertJsonPath('root.kind', 'server')
        ->assertJsonPath('durationMs', 1000)
        ->assertJsonPath('error', false)
        ->assertJsonPath('spanCount', 2)
        ->assertJsonPath('waterfall.0.span.name', 'GET /orders')
        ->assertJsonPath('waterfall.0.depth', 0)
        ->assertJsonPath('waterfall.1.span.name', 'db.query')
        ->assertJsonPath('waterfall.1.depth', 1)
        ->assertJsonPath('waterfall.1.span.attributes', ['db.query.text' => 'select * from orders'])
        ->assertJsonPath('waterfall.1.offsetPct', 20)
        ->assertJsonPath('waterfall.1.widthPct', 20)
        ->assertJsonStructure(['chain', 'identities', 'context', 'profile', 'report', 'logs', 'dimensionLinks', 'services']);
});

it('attaches the trace logs from loki', function (): void {
    fakeTrace();

    $logs = $this->getJson(apiUrl('traces/'.DRAWER_TRACE))->assertOk()->json('logs');

    expect(json_encode($logs))->toContain('Order list rendered');
});

it('links declared dimensions out to the host app', function (): void {
    fakeTrace();
    TelemetryUi::dimension('billing.customer_id', label: 'Customer', group: 'Billing', link: 'https://crm.test/customers/{value}');

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('dimensionLinks', ['billing.customer_id' => 'https://crm.test/customers/8655']);
});

it('serves browser/RUM spans as frontend rows in a unified trace', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'cbox-web']]]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 's1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1000000000', 'endTimeUnixNano' => '2000000000'],
                    ['spanId' => 'b1', 'parentSpanId' => 's1', 'name' => 'document.load', 'kind' => 'SPAN_KIND_CLIENT', 'startTimeUnixNano' => '1100000000', 'endTimeUnixNano' => '1600000000', 'attributes' => [
                        ['key' => 'browser', 'value' => ['boolValue' => true]],
                        ['key' => 'browser.ttfb_ms', 'value' => ['intValue' => '120']],
                        ['key' => 'browser.dom_interactive_ms', 'value' => ['intValue' => '250']],
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://app.test/orders']],
                    ]],
                    ['spanId' => 'b2', 'parentSpanId' => 'b1', 'name' => 'fetch GET', 'kind' => 'SPAN_KIND_CLIENT', 'startTimeUnixNano' => '1200000000', 'endTimeUnixNano' => '1400000000', 'attributes' => [
                        ['key' => 'browser', 'value' => ['boolValue' => true]],
                        ['key' => 'http.url', 'value' => ['stringValue' => '/api/orders']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                    ]],
                ]]],
            ]],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $response = $this->getJson(apiUrl('traces/'.DRAWER_TRACE))->assertOk();

    expect($response->json('waterfall.0.span.browser'))->toBeFalse()
        ->and($response->json('waterfall.1.span.name'))->toBe('document.load')
        ->and($response->json('waterfall.1.span.browser'))->toBeTrue()
        ->and($response->json('waterfall.1.span.summary'))->toContain('TTFB 120ms')
        ->and($response->json('waterfall.2.span.summary'))->toContain('/api/orders → 200');
});

it('serves the host/runtime context beside the waterfall', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    fakeTrace([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '0.42'], [1735689660, '0.71']]],
            ]],
        ]),
    ]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('context.0.label', 'Host CPU')
        ->assertJsonPath('context.0.current', 0.71);

    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'host_name="web-3"'));
});

it('keeps the waterfall when correlation backends are down', function (): void {
    fakeTrace([
        'prometheus.test:9090/*' => Http::response('down', 503),
        'loki.test:3100/*' => Http::response('down', 503),
    ]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('root.name', 'GET /orders')
        ->assertJsonPath('context', [])
        ->assertJsonPath('logs', []);
});

it('404s a trace the backend no longer has, with a typed error', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response(['batches' => []])]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertNotFound()
        ->assertJsonPath('error.type', 'not_found');
});

it('surfaces a trace backend failure as a typed 502', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('boom', 502)]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertStatus(502)
        ->assertJsonPath('error.type', 'backend')
        ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, '502'));
});

it('rejects a trace id that is not hex at the route', function (): void {
    $this->getJson(apiUrl('traces/not-a-trace'))->assertNotFound();
});

it('rejects an error-group id carrying query metacharacters', function (): void {
    Http::fake();

    // The route admits only alphanumerics (stricter than the report's own
    // validId(), whose 422 is a second line of defence), so a crafted id
    // never reaches a LogQL/TraceQL builder at all.
    $this->getJson(apiUrl('errors/'.rawurlencode('abc"} |= "x')))->assertNotFound();
    $this->getJson(apiUrl('errors/'.str_repeat('a', 65)))->assertNotFound();

    Http::assertNothingSent();
});

it('serves an error group report with the compose draft when the viewer can file tickets', function (): void {
    githubIssues();
    $now = time();

    Http::fake([
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['service_name' => 'checkout', 'exception_group' => 'abc123def456', 'exception_type' => 'TimeoutException', 'exception_message' => 'cURL timed out', 'trace_id' => DRAWER_TRACE, 'user_id' => '7'], 'values' => [
                [(string) (($now - 120) * 1_000_000_000), 'TimeoutException: cURL timed out'],
                [(string) (($now - 60) * 1_000_000_000), 'TimeoutException: cURL timed out'],
            ]],
        ]]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $response = $this->getJson(apiUrl('errors/abc123def456'))
        ->assertOk()
        ->assertJsonPath('group', 'abc123def456')
        ->assertJsonPath('canCreateIssue', true)
        ->assertJsonPath('tracker', 'cboxdk/laravel-telemetry-ui')
        ->assertJsonStructure(['stats', 'occurrences', 'detail', 'request', 'suspect', 'releases', 'lookbackDays', 'draft' => ['title', 'body'], 'llm']);

    expect($response->json('draft.title'))->toContain('TimeoutException')
        ->and($response->json('llm'))->toContain('TimeoutException');

    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'exception_group="abc123def456"'));
});

it('offers no draft when the viewer cannot file tickets', function (): void {
    githubIssues();
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);

    Http::fake([
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $this->getJson(apiUrl('errors/abc123def456'))
        ->assertOk()
        ->assertJsonPath('canCreateIssue', false)
        ->assertJsonPath('draft', null);
});

it('serves a tracker issue with the trace ids it mentions', function (): void {
    githubIssues();

    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues/7' => Http::response([
            'number' => 7,
            'title' => 'Timeout on trace '.DRAWER_TRACE,
            'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/7',
            'user' => ['login' => 'octocat'],
            'labels' => [['name' => 'bug']],
            'comments' => 2,
            'body' => 'Seen in production. trace '.DRAWER_TRACE,
            'updated_at' => '2026-07-03T12:00:00Z',
        ]),
    ]);

    $this->getJson(apiUrl('issues/'.rawurlencode('#7')))
        ->assertOk()
        ->assertJsonPath('id', '#7')
        ->assertJsonPath('title', 'Timeout on trace '.DRAWER_TRACE)
        ->assertJsonPath('open', true)
        ->assertJsonPath('author', 'octocat')
        ->assertJsonPath('labels', ['bug'])
        ->assertJsonPath('traceIds', [DRAWER_TRACE]);
});

it('404s an issue when no tracker is configured, or the tracker lacks it', function (): void {
    $this->getJson(apiUrl('issues/7'))->assertNotFound()->assertJsonPath('error.type', 'not_found');

    githubIssues();
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $response = $this->getJson(apiUrl('issues/7'));

    expect($response->status())->toBeIn([404, 502])
        ->and($response->json('error.type'))->toBeIn(['not_found', 'backend']);
});

it('creates a ticket via the tracker and returns the new issue', function (): void {
    githubIssues();

    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues' => Http::response([
            'number' => 100, 'title' => 'TimeoutException — 12', 'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/100',
            'body' => 'the analysis', 'labels' => [['name' => 'bug']],
        ], 201),
    ]);

    $this->postJson(apiUrl('issues'), ['title' => 'TimeoutException — 12', 'body' => 'the analysis', 'labels' => ['bug']])
        ->assertCreated()
        ->assertJsonPath('id', '#100')
        ->assertJsonPath('title', 'TimeoutException — 12')
        ->assertJsonPath('url', 'https://github.com/cboxdk/laravel-telemetry-ui/issues/100');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/repos/cboxdk/laravel-telemetry-ui/issues')
        && $request['title'] === 'TimeoutException — 12'
        && $request['body'] === 'the analysis'
        && $request['labels'] === ['bug']);
});

it('requires a title to create a ticket', function (): void {
    githubIssues();
    Http::fake();

    $this->postJson(apiUrl('issues'), ['title' => '   ', 'body' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid')
        ->assertJsonPath('error.message', 'A title is required.');

    Http::assertNothingSent();
});

it('refuses to create a ticket when no writable tracker is configured', function (): void {
    $this->postJson(apiUrl('issues'), ['title' => 'Boom'])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid');
});

it('forbids ticket creation without the manage ability', function (): void {
    githubIssues();
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);
    Http::fake();

    $this->postJson(apiUrl('issues'), ['title' => 'Boom'])->assertForbidden()->assertJsonPath('error.type', 'forbidden');

    Http::assertNothingSent();
});

it('explains why a trace failed with the exception its trace id carries', function (): void {
    fakeTrace(['loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['service_name' => 'checkout', 'level' => 'error', 'trace_id' => DRAWER_TRACE, 'exception_group' => '0f3a9c2b1d4e', 'exception_type' => 'App\\PaymentFailed', 'exception_message' => 'Card declined', 'exception_file' => 'app/Payments/Charge.php', 'exception_line' => '42'], 'values' => [['1735689600500000000', 'exception']]],
    ]]])]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('exceptions.0.group', '0f3a9c2b1d4e')
        ->assertJsonPath('exceptions.0.type', 'App\\PaymentFailed')
        ->assertJsonPath('exceptions.0.line', 42)
        ->assertJsonPath('exceptions.0.match', 'trace');
});

it('falls back to the service and time window when exception records carry no trace id', function (): void {
    fakeTrace(['loki.test:3100/*' => function (Request $request) {
        $q = (string) (requestQuery($request)['query'] ?? '');

        // The trace-id lookup finds nothing; the time-window lookup finds the record.
        return Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => str_contains($q, 'trace_id')
            ? []
            : [['stream' => ['service_name' => 'checkout', 'exception_group' => 'ab12cd34ef56', 'exception_type' => 'RuntimeException', 'exception_message' => 'boom'], 'values' => [['1735689600700000000', 'exception']]]],
        ]]);
    }]);

    $this->getJson(apiUrl('traces/'.DRAWER_TRACE))
        ->assertOk()
        ->assertJsonPath('exceptions.0.group', 'ab12cd34ef56')
        ->assertJsonPath('exceptions.0.match', 'time');
});
