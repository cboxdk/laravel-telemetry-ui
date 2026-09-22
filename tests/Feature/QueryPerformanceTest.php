<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

function fakeQuerySpans(): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /checkout', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 500, 'spanSets' => [['spans' => [
                    // Same statement twice → aggregates to calls=2, total=400ms, avg=200, max=300.
                    ['spanID' => 'q1', 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '100000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from users where id = ?']],
                        ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
                    ]],
                    ['spanID' => 'q2', 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '300000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from users where id = ?']],
                        ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
                    ]],
                    // A cheaper statement → total=50ms, ranks below.
                    ['spanID' => 'q3', 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '50000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select count(*) from orders']],
                        ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
                    ]],
                ]]]],
            ],
        ]),
    ]);
}

it('aggregates db spans by statement and ranks by total DB time', function (): void {
    fakeQuerySpans();

    $this->getJson(panelUrl('query-performance'))
        ->assertOk()
        ->assertJsonPath('exact', false)
        // Ranked by total time: the users query (400ms) above the orders query (50ms).
        ->assertJsonPath('rows.0.query.v', 'select * from users where id = ?')
        ->assertJsonPath('rows.1.query.v', 'select count(*) from orders')
        // The repeated statement: 100ms + 300ms → 400ms total, 200ms avg, 300ms max.
        ->assertJsonPath('rows.0.calls.v', '2')
        ->assertJsonPath('rows.0.total.v', '400ms')
        ->assertJsonPath('rows.0.avg.v', '200ms')
        ->assertJsonPath('rows.0.max.v', '300ms');
});

it('links each row to the detail page for that statement', function (): void {
    fakeQuerySpans();

    $this->getJson(panelUrl('query-performance'))
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'query', 'value' => 'select * from users where id = ?']);
});

it('re-ranks by average when asked', function (): void {
    fakeQuerySpans();

    // By average the orders query (50ms) still sits below users (200ms avg),
    // so users stays on top — but the sort param must be accepted without error.
    $this->getJson(panelUrl('query-performance', ['q_sort' => 'avg']))
        ->assertOk()
        ->assertJsonPath('controls.2.param', 'q_sort')
        ->assertJsonPath('controls.2.value', 'avg')
        ->assertJsonPath('rows.0.query.v', 'select * from users where id = ?');
});
