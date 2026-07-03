<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TraceDrawer;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
        ->assertSeeHtml('is-open');
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
