<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TraceDrawer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Creating tickets requires the write ability; allow it by default so the
// compose tests exercise the happy path (a dedicated test revokes it).
beforeEach(fn () => Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true));

function fakeTrace(): void
{
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'checkout']]]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1000000000', 'endTimeUnixNano' => '2000000000'],
                    ['spanId' => 'a2', 'parentSpanId' => 'a1', 'name' => 'db.query', 'kind' => 3, 'startTimeUnixNano' => '1200000000', 'endTimeUnixNano' => '1400000000', 'attributes' => [['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from orders']]]],
                ]]],
            ]],
        ]),
    ]);
}

it('is closed by default', function (): void {
    Livewire::test(TraceDrawer::class)
        ->assertSet('traceId', '')
        ->assertSet('stack', [])
        ->assertDontSee('GET /orders');
});

it('stacks a trace on top of an issue and pops back to it', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_test',
    ]);
    fakeTrace();
    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues/7' => Http::response([
            'number' => 7, 'title' => 'Flaky checkout', 'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/7',
            'body' => 'investigating', 'updated_at' => '2026-07-03T12:00:00Z',
        ]),
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [['resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'checkout']]]],
                'scopeSpans' => [['spans' => [['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1000000000', 'endTimeUnixNano' => '2000000000']]]]]],
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-issue', issueId: '#7')
        ->assertSee('Flaky checkout')
        // Dig into a trace from within the issue — it stacks.
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertCount('stack', 2)
        ->assertSet('traceId', 'abc123abc123abc123abc123abc123ab')
        ->assertSet('issueId', '')
        ->assertSee('GET /orders')
        // Back restores the issue with its context.
        ->call('back')
        ->assertCount('stack', 1)
        ->assertSet('issueId', '#7')
        ->assertSet('traceId', '')
        ->assertSee('Flaky checkout')
        ->call('close')
        ->assertSet('stack', []);
});

it('opens and renders a trace on the open-trace event', function (): void {
    fakeTrace();

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSet('traceId', 'abc123abc123abc123abc123abc123ab')
        ->assertSee('GET /orders')
        ->assertSee('select * from orders')
        ->assertSeeHtml('is-open')
        // Span attributes are dimensional drill-down links (click a value → filter).
        ->assertSeeHtml('tui-attr-filter');
});

it('renders browser/RUM spans as frontend rows in a unified trace', function (): void {
    // A server request (root) with browser spans hung off it via traceparent:
    // the page-load span is a child of the server span, and a fetch under that.
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
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSee('GET /orders')       // backend root
        ->assertSee('document.load')     // browser page-load span, nested in the same trace
        ->assertSeeHtml('tui-badge-web') // frontend spans carry the browser badge
        ->assertSee('TTFB 120ms')        // RUM navigation-timing summary
        ->assertSee('/api/orders → 200'); // browser fetch renders its URL + status
});

it('renders the host/runtime context strip beside the waterfall', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                    ['key' => 'host.name', 'value' => ['stringValue' => 'web-3']],
                ]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
                ]]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '0.42'], [1735689660, '0.71']]],
            ]],
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSee('GET /orders')
        ->assertSee('Context')
        ->assertSee('Host CPU')
        ->assertSee('71%'); // last value of the padded window
});

it('opens from a deep-linked ?trace= id and closes cleanly', function (): void {
    fakeTrace();

    Livewire::withQueryParams(['trace' => 'abc123abc123abc123abc123abc123ab'])
        ->test(TraceDrawer::class)
        ->assertSee('GET /orders')
        ->call('close')
        ->assertSet('traceId', '')
        ->assertDontSee('GET /orders');
});

it('surfaces a backend error inside the drawer', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('boom', 502)]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSee('status 502');
});

it('composes and creates a ticket, then lands on the new issue', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_write',
    ]);

    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues' => Http::response([
            'number' => 100, 'title' => 'TimeoutException — 12', 'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/100',
            'body' => 'the analysis',
        ], 201),
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues/100' => Http::response([
            'number' => 100, 'title' => 'TimeoutException — 12', 'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/100', 'body' => 'the analysis',
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:compose-ticket', title: 'TimeoutException — 12', body: 'the analysis', labels: ['bug'])
        ->assertSet('composing', true)
        ->assertSee('Create ticket')
        ->call('submitTicket')
        ->assertSet('composing', false)
        ->assertCount('stack', 1)
        ->assertSet('issueId', '#100')
        ->assertSee('TimeoutException — 12');
});

it('keeps the compose form open and shows an error when title is empty', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_write',
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:compose-ticket', title: '', body: 'x', labels: [])
        ->call('submitTicket')
        ->assertSet('composing', true)
        ->assertSee('title is required');
});

it('opens an issue in the drawer and clears any open trace', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_test',
    ]);

    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues/7' => Http::response([
            'number' => 7,
            'title' => 'Timeout on trace abc123abc123abc123abc123abc123ab',
            'state' => 'open',
            'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/7',
            'user' => ['login' => 'octocat'],
            'labels' => [['name' => 'bug']],
            'comments' => 2,
            'body' => 'Seen in production. trace abc123abc123abc123abc123abc123ab',
            'updated_at' => '2026-07-03T12:00:00Z',
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->set('traceId', 'ffffffffffffffffffffffffffffffff')
        ->dispatch('telemetry-ui:open-issue', issueId: '#7')
        ->assertSet('issueId', '#7')
        ->assertSet('traceId', '')
        ->assertSee('Timeout on trace')
        ->assertSee('Seen in production')
        // The trace id in the body becomes a drawer link.
        ->assertSeeHtml('data-trace-id="abc123abc123abc123abc123abc123ab"');
});
