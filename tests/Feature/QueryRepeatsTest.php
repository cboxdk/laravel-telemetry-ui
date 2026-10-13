<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Builtin\DuplicateQueries;
use Cbox\TelemetryUi\Panels\Builtin\NPlusOneQueries;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Loki streams of repeated-query records: each [labels, [[ns, line], …]].
 *
 * @param  list<array{0: array<string, string>, 1: list<array{0: string, 1: string}>}>  $streams
 */
function fakeRepeatRecords(array $streams): void
{
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => array_map(
                static fn (array $s): array => ['stream' => ['service_name' => 'demo', ...$s[0]], 'values' => $s[1]],
                $streams,
            )],
        ]),
    ]);
}

it('groups N+1 detections by query shape and shows the line that ran them', function (): void {
    $base = [
        'laravel_db_query_shape' => 'bbbb111111111111',
        'laravel_db_connection' => 'mysql',
        'code_file_path' => 'app/Http/Controllers/OrderController.php',
        'code_line_number' => '42',
        'code_function_name' => 'App\\Http\\Controllers\\OrderController::index',
    ];

    fakeRepeatRecords([
        // Two statement texts, one shape (an IN list of a different length):
        // one row, two traces affected.
        [[...$base, 'db_query_text' => 'select * from `users` where `id` in (?, ?)', 'db_query_repeat_count' => '7', 'trace_id' => 'aaaa1111aaaa1111aaaa1111aaaa1111'],
            [['1735689500000000000', 'db.query.n_plus_one_detected']]],
        [[...$base, 'db_query_text' => 'select * from `users` where `id` in (?, ?, ?)', 'db_query_repeat_count' => '30', 'trace_id' => 'bbbb2222bbbb2222bbbb2222bbbb2222'],
            [['1735689600000000000', 'db.query.n_plus_one_detected']]],
        // A duplicate record in the same read is not an N+1.
        [['db_query_text' => 'select 1', 'laravel_db_query_shape' => 'cccc', 'trace_id' => 'cccc3333cccc3333cccc3333cccc3333'],
            [['1735689600000000000', 'db.query.duplicate_detected']]],
    ]);

    $this->getJson(panelUrl(NPlusOneQueries::id()))
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        // The newest example text, and the newest trace.
        ->assertJsonPath('rows.0.query.v', 'select * from `users` where `id` in (?, ?, ?)')
        ->assertJsonPath('rows.0.origin.v', 'app/Http/Controllers/OrderController.php:42')
        ->assertJsonPath('rows.0.origin.sub', 'App\\Http\\Controllers\\OrderController::index')
        ->assertJsonPath('rows.0.traces.raw', 2)
        ->assertJsonPath('rows.0.repeat.v', '×30')
        ->assertJsonPath('rows.0.trace.link.id', 'bbbb2222bbbb2222bbbb2222bbbb2222');

    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode(requestQuery($request)['query'] ?? ''), '|= "db.query.n_plus_one_detected"'));
});

it('groups identical-repeat detections by shape, falling back to the text on older records', function (): void {
    fakeRepeatRecords([
        [['db_query_text' => 'select * from `settings` where `key` = ?', 'laravel_db_query_shape' => 'dddd', 'db_query_repeat_count' => '4', 'trace_id' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'code_file_path' => 'app/Support/Settings.php', 'code_line_number' => '12'],
            [['1735689600000000000', 'db.query.duplicate_detected'], ['1735689500000000000', 'db.query.duplicate_detected']]],
        // Older record: no shape, grouped by its text.
        [['db_query_text' => 'select * from users where id = ?', 'db_namespace' => 'mysql', 'db_query_repeat_count' => '9', 'trace_id' => 'eeee1111eeee1111eeee1111eeee1111'],
            [['1735689400000000000', 'db.query.duplicate_detected']]],
    ]);

    $this->getJson(panelUrl(DuplicateQueries::id()))
        ->assertOk()
        ->assertJsonPath('title', 'Duplicate queries')
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.query.v', 'select * from `settings` where `key` = ?')
        ->assertJsonPath('rows.0.query.sub', 'app/Support/Settings.php:12')
        ->assertJsonPath('rows.0.traces.raw', 2)
        ->assertJsonPath('rows.1.query.v', 'select * from users where id = ?')
        ->assertJsonPath('rows.1.repeat.v', '×9');
});

it('folds query performance by shape when the spans carry one', function (): void {
    $span = static fn (string $id, string $text, ?string $shape, int $ms): array => [
        'spanID' => $id, 'name' => 'db.query', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => (string) ($ms * 1_000_000),
        'attributes' => array_values(array_filter([
            ['key' => 'db.query.text', 'value' => ['stringValue' => $text]],
            ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
            $shape !== null ? ['key' => 'laravel.db.query.shape', 'value' => ['stringValue' => $shape]] : null,
        ])),
    ];

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [[
            'traceID' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /x',
            'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 500,
            'spanSets' => [['spans' => [
                $span('q1', 'select * from `users` where `id` in (?, ?)', 'bbbb111111111111', 100),
                $span('q2', 'select * from `users` where `id` in (?, ?, ?)', 'bbbb111111111111', 300),
                $span('q3', 'select count(*) from orders', null, 50),
            ]]],
        ]]]),
    ]);

    $this->getJson(panelUrl('query-performance'))
        ->assertOk()
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.calls.v', '2')
        ->assertJsonPath('rows.0.total.v', '400ms')
        ->assertJsonPath('rows.1.query.v', 'select count(*) from orders');

    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'span.laravel.db.query.shape'));
});
