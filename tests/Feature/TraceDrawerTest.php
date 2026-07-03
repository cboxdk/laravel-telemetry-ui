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
        ->assertDontSee('GET /orders');
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
