<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\Detail\QueryDetail;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('shows one statement in depth: stats, callers and example traces', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'cccc2222cccc2222cccc2222cccc2222', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /checkout', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 500, 'spanSets' => [['spans' => [
                    ['spanID' => 'd1', 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '100000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from users where id = ?']],
                        ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
                    ]],
                    ['spanID' => 'd2', 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '300000000', 'attributes' => [
                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from users where id = ?']],
                        ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
                    ]],
                ]]]],
            ],
        ]),
    ]);

    Livewire::withQueryParams(['dbq' => 'select * from users where id = ?'])
        ->test(QueryDetail::class)
        ->assertOk()
        ->assertSee('select * from users where id = ?')
        ->assertSee('mysql')
        ->assertSee('Called by')
        ->assertSee('GET /checkout')          // the calling route
        ->assertSee('Slowest example traces')
        ->assertSeeHtml('data-trace-id="cccc2222cccc2222cccc2222cccc2222"');

    // The exact statement is matched in the TraceQL.
    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.db.query.text = "select * from users where id = ?"');
    });
});

it('is quiet with no statement selected', function (): void {
    Livewire::test(QueryDetail::class)
        ->assertOk()
        ->assertSee('No statement selected');
});
