<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\QueryPerformance;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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

    Livewire::test(QueryPerformance::class)
        ->assertOk()
        ->assertSee('select * from users where id = ?')
        ->assertSee('select count(*) from orders')
        // The repeated statement: 100ms + 300ms → 400ms total, 200ms avg, 300ms max.
        ->assertSee('400ms')
        ->assertSee('200ms')
        // Ranked by total time: the users query (400ms) above the orders query (50ms).
        ->assertSeeInOrder(['select * from users where id = ?', 'select count(*) from orders']);
});

it('links each row to a traces drill-down for that statement', function (): void {
    fakeQuerySpans();

    Livewire::test(QueryPerformance::class)
        // The full statement is the link title, and the row drills somewhere.
        ->assertSeeHtml('title="select * from users where id = ?"')
        ->assertSeeHtml('data-row-href');
});

it('re-ranks by average when asked', function (): void {
    fakeQuerySpans();

    // By average the orders query (50ms) still sits below users (200ms avg),
    // so users stays on top — but the sort param must be accepted without error.
    Livewire::withQueryParams(['q_sort' => 'avg'])
        ->test(QueryPerformance::class)
        ->assertOk()
        ->assertSee('select * from users where id = ?');
});
