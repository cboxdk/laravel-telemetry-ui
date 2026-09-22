<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

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

    $this->getJson(panelUrl('query-detail', ['dbq' => 'select * from users where id = ?']))
        ->assertOk()
        ->assertJsonPath('kind', 'composite')
        ->assertJsonPath('badges', ['mysql'])
        ->assertJsonPath('back', ['to' => 'page', 'page' => 'queries'])
        ->assertJsonPath('parts.0.kind', 'code')
        ->assertJsonPath('parts.0.text', 'select * from users where id = ?')
        // 100ms + 300ms in one trace → 2 calls, 200ms avg, 300ms max.
        ->assertJsonPath('parts.1.items.0.value', '2')
        ->assertJsonPath('parts.1.items.1.value', '200ms')
        ->assertJsonPath('parts.2.title', 'Slowest example traces')
        ->assertJsonPath('parts.2.rows.0._link', ['to' => 'trace', 'id' => 'cccc2222cccc2222cccc2222cccc2222'])
        ->assertJsonPath('parts.3.title', 'Called by')
        ->assertJsonPath('parts.3.rows.0.origin.v', 'GET /checkout'); // the calling route

    // The exact statement is matched in the TraceQL.
    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.db.query.text = "select * from users where id = ?"');
    });
});

it('is quiet with no statement selected', function (): void {
    $this->getJson(panelUrl('query-detail'))
        ->assertOk()
        ->assertJsonPath('parts', [])
        ->assertJsonPath('empty', 'No statement selected.');

    Http::assertNothingSent();
});
