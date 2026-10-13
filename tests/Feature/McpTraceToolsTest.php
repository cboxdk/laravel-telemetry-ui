<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\CompareTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\ListTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceSummaryTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** One OTLP attribute, typed the way Tempo returns it. */
function mcpAttr(string $key, string|int|float $value): array
{
    return ['key' => $key, 'value' => match (true) {
        is_int($value) => ['intValue' => (string) $value],
        is_float($value) => ['doubleValue' => $value],
        default => ['stringValue' => $value],
    }];
}

/**
 * A Tempo v1 trace payload: a root request and $loop runs of an N+1 query
 * (marked from the third run, attributed to a controller line).
 */
function mcpTracePayload(int $loop, string $label, int $rootMs = 400, bool $error = false): array
{
    $t0 = 1_735_689_600_000_000_000;
    $spans = [[
        'spanId' => 'root', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER',
        'startTimeUnixNano' => (string) $t0, 'endTimeUnixNano' => (string) ($t0 + $rootMs * 1_000_000),
        'status' => $error ? ['code' => 'STATUS_CODE_ERROR'] : [],
        'attributes' => [
            mcpAttr('http.response.status_code', $error ? 500 : 200),
            mcpAttr('laravel.trace.label', $label),
            mcpAttr('db.query.count', $loop + 1),
            mcpAttr('db.query.n_plus_one.count', $loop >= 3 ? 1 : 0),
            mcpAttr('telemetry.overhead_ms', 0.8),
        ],
    ], [
        'spanId' => 'q0', 'parentSpanId' => 'root', 'name' => 'db.query', 'kind' => 'SPAN_KIND_CLIENT',
        'startTimeUnixNano' => (string) ($t0 + 1_000_000), 'endTimeUnixNano' => (string) ($t0 + 5_000_000),
        'attributes' => [mcpAttr('db.system.name', 'mysql'), mcpAttr('db.query.text', 'select * from `orders`'), mcpAttr('laravel.db.query.shape', 'aaaa000000000000')],
    ]];

    for ($i = 1; $i <= $loop; $i++) {
        $start = $t0 + (10 + $i * 3) * 1_000_000;
        $spans[] = [
            'spanId' => "q{$i}", 'parentSpanId' => 'root', 'name' => 'db.query', 'kind' => 'SPAN_KIND_CLIENT',
            'startTimeUnixNano' => (string) $start, 'endTimeUnixNano' => (string) ($start + 2_000_000),
            'attributes' => [
                mcpAttr('db.system.name', 'mysql'),
                mcpAttr('db.query.text', 'select * from `users` where `users`.`id` = ? limit 1'),
                mcpAttr('laravel.db.query.shape', 'bbbb111111111111'),
                ...($i >= 3 ? [
                    mcpAttr('laravel.db.query.repeat', 'n_plus_one'),
                    mcpAttr('code.file.path', 'app/Http/Controllers/OrderController.php'),
                    mcpAttr('code.line.number', 42),
                ] : []),
            ],
        ];
    }

    return ['batches' => [[
        'resource' => ['attributes' => [mcpAttr('service.name', 'shop')]],
        'scopeSpans' => [['spans' => $spans]],
    ]]];
}

/** A Tempo search hit whose matched root span carries $attributes. */
function mcpSearchHit(string $traceId, int $startSecondsAgo, array $attributes = [], float $durationMs = 400): array
{
    return [
        'traceID' => $traceId,
        'rootServiceName' => 'shop',
        'rootTraceName' => 'GET /orders',
        'startTimeUnixNano' => (string) ((time() - $startSecondsAgo) * 1_000_000_000),
        'durationMs' => $durationMs,
        'spanSets' => [['spans' => [[
            'spanID' => 'root',
            'startTimeUnixNano' => (string) ((time() - $startSecondsAgo) * 1_000_000_000),
            'durationNanos' => (string) ($durationMs * 1_000_000),
            'attributes' => array_map(static fn ($k, $v): array => mcpAttr($k, $v), array_keys($attributes), $attributes),
        ]]]],
    ];
}

const MCP_EMPTY_LOKI = ['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]];

it('summarises a trace: identity, totals, and the N+1 with the line that ran it', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(mcpTracePayload(12, 'before')),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(TraceSummaryTool::class, ['trace_id' => 'abc123abc123abc123abc123abc123ab'])
        ->assertOk()
        ->assertSee('"name":"GET /orders"')
        ->assertSee('"label":"before"')
        ->assertSee('"n_plus_one_shapes":1')
        ->assertSee('"level":"warning","section":"db","message":"N+1: 12× select * from `users` where `users`.`id` = ? limit 1","origin":"app/Http/Controllers/OrderController.php:42"')
        // Compact by default: no per-flag detail, no internal keys.
        ->assertDontSee('"detail"')
        ->assertDontSee('"key"')
        ->assertDontSee('"next_offset"');
});

it('pages the flags and adds detail when verbose', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(mcpTracePayload(12, 'before', error: true)),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    // Error (5xx) first, then the N+1: page one holds just the error.
    TelemetryServer::tool(TraceSummaryTool::class, ['trace_id' => 'abc123abc123abc123abc123abc123ab', 'limit' => 1])
        ->assertOk()
        ->assertSee('"message":"Responded 500"')
        ->assertDontSee('N+1: 12×')
        ->assertSee('"flags_total":2')
        ->assertSee('"next_offset":1');

    TelemetryServer::tool(TraceSummaryTool::class, ['trace_id' => 'abc123abc123abc123abc123abc123ab', 'limit' => 1, 'offset' => 1, 'verbose' => true])
        ->assertOk()
        ->assertSee('N+1: 12×')
        ->assertSee('"span_ids"')
        ->assertSee('"shapes"');
});

it('says so when a trace has no spans', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => []]),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(TraceSummaryTool::class, ['trace_id' => 'abc123abc123abc123abc123abc123ab'])
        ->assertHasErrors();
});

it('compares two labelled traces, resolving each label to its newest trace', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => function (Request $request) {
            $q = rawurldecode(requestQuery($request)['q'] ?? '');

            return Http::response(['traces' => str_contains($q, '"after"')
                ? [mcpSearchHit('bbbb0000bbbb0000bbbb0000bbbb0000', 60)]
                // Two "before" runs; the newer one is compared.
                : [mcpSearchHit('aaaa0000aaaa0000aaaa0000aaaa0000', 3600), mcpSearchHit('aaaa1111aaaa1111aaaa1111aaaa1111', 600)]]);
        },
        'tempo.test:3200/api/traces/bbbb*' => Http::response(mcpTracePayload(1, 'after', 120)),
        'tempo.test:3200/api/traces/aaaa1111*' => Http::response(mcpTracePayload(12, 'before', 400)),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(CompareTracesTool::class, ['a' => 'before', 'b' => 'after'])
        ->assertOk()
        ->assertSee('"trace_id":"aaaa1111aaaa1111aaaa1111aaaa1111"')
        ->assertSee('"duration_ms":{"a":400,"b":120,"delta":-280,"change_pct":-70}')
        ->assertSee('"changed":1')
        ->assertSee('"only_a":[{"level":"warning","section":"db","message":"N+1: 12×');

    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'span.laravel.trace.label = "before"'));
});

it('errors when a label has no trace', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    TelemetryServer::tool(CompareTracesTool::class, ['a' => 'nope', 'b' => 'abc123abc123abc123abc123abc123ab'])
        ->assertHasErrors(['No trace labelled "nope"']);
});

it('lists recent traces with flag hints, and only the flagged ones on request', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => function (Request $request) {
            $q = rawurldecode(requestQuery($request)['q'] ?? '');

            return Http::response(['traces' => match (true) {
                str_contains($q, 'status = error') => [mcpSearchHit('cccc0000cccc0000cccc0000cccc0000', 30)],
                str_contains($q, 'duration >= 100ms') => [],
                default => [
                    mcpSearchHit('aaaa0000aaaa0000aaaa0000aaaa0000', 10, ['laravel.trace.label' => 'before', 'db.query.n_plus_one.count' => 1, 'db.query.count' => 13]),
                    mcpSearchHit('bbbb0000bbbb0000bbbb0000bbbb0000', 20, ['db.query.count' => 2]),
                    mcpSearchHit('cccc0000cccc0000cccc0000cccc0000', 30),
                ],
            }]);
        },
    ]);

    TelemetryServer::tool(ListTracesTool::class)
        ->assertOk()
        ->assertSee('"trace_id":"aaaa0000aaaa0000aaaa0000aaaa0000"')
        ->assertSee('"label":"before","queries":13,"flags":["n_plus_one"]')
        ->assertSee('"trace_id":"bbbb0000bbbb0000bbbb0000bbbb0000"')
        ->assertSee('"flags":["error"]');

    TelemetryServer::tool(ListTracesTool::class, ['has_flags' => true])
        ->assertOk()
        ->assertSee('aaaa0000aaaa0000aaaa0000aaaa0000')
        ->assertSee('cccc0000cccc0000cccc0000cccc0000')
        ->assertDontSee('bbbb0000bbbb0000bbbb0000bbbb0000');

    // Without a label it asks for root spans.
    Http::assertSent(fn (Request $request): bool => str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'nestedSetParent < 0'));
});

/** The JSON a tool answered with. */
function mcpJson(object $response): array
{
    $rpc = (fn () => $this->response)->call($response)->toArray();

    return json_decode($rpc['result']['content'][0]['text'] ?? '{}', true) ?? [];
}

/** A Tempo search fake that honours the `end` bound, as the real one does. */
function mcpHonoursEnd(array $hits): Closure
{
    return function (Request $request) use ($hits) {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');
        $end = (int) (requestQuery($request)['end'] ?? PHP_INT_MAX);

        return Http::response(['traces' => str_contains($q, 'status = error') || str_contains($q, 'duration >=') ? [] : array_values(array_filter(
            $hits,
            static fn (array $hit): bool => intdiv((int) $hit['startTimeUnixNano'], 1_000_000_000) <= $end,
        ))]);
    };
}

it('pages a trace list backwards in time, without overlap', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => mcpHonoursEnd([
        mcpSearchHit('aaaa0000aaaa0000aaaa0000aaaa0000', 10),
        mcpSearchHit('bbbb0000bbbb0000bbbb0000bbbb0000', 20),
        // Two traces in the same second: the cursor must split them cleanly.
        mcpSearchHit('cccc0000cccc0000cccc0000cccc0000', 30),
        mcpSearchHit('dddd0000dddd0000dddd0000dddd0000', 30),
        mcpSearchHit('eeee0000eeee0000eeee0000eeee0000', 40),
    ])]);

    $seen = [];
    $cursor = null;

    for ($page = 0; $page < 5; $page++) {
        $response = TelemetryServer::tool(ListTracesTool::class, ['limit' => 2, ...($cursor !== null ? ['cursor' => $cursor] : [])])->assertOk();
        $json = mcpJson($response);
        $seen = [...$seen, ...array_column($json['traces'] ?? [], 'trace_id')];
        $cursor = $json['next_cursor'] ?? null;

        if ($cursor === null) {
            break;
        }
    }

    expect(array_map(fn (string $id): string => substr($id, 0, 4), $seen))->toBe(['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee']);
});

it('offers the next page of a flagged list whenever the fetch was full', function (): void {
    // 20 unflagged traces fill a has_flags fetch of 2 × 4 + 1 without one hit:
    // the page is empty, but there may be flagged traces further back.
    Http::fake(['tempo.test:3200/api/search*' => mcpHonoursEnd(array_map(
        fn (int $i): array => mcpSearchHit(sprintf('%032x', $i + 1), 10 + $i),
        range(0, 19),
    ))]);

    TelemetryServer::tool(ListTracesTool::class, ['limit' => 2, 'has_flags' => true])
        ->assertOk()
        ->assertSee('"next_cursor"')
        ->assertSee('pass next_cursor');
});

it('falls back to a plain search when the backend refuses the root-span intrinsic', function (): void {
    // telemetryd's TraceQL parser has no nestedSetParent.
    Http::fake([
        'tempo.test:3200/api/search*' => function (Request $request) {
            $q = rawurldecode(requestQuery($request)['q'] ?? '');

            if (str_contains($q, 'nestedSetParent')) {
                return Http::response('parse error: unknown intrinsic nestedSetParent', 400);
            }

            return Http::response(['traces' => str_contains($q, 'status = error') || str_contains($q, 'duration >=') ? [] : [
                mcpSearchHit('aaaa1111aaaa1111aaaa1111aaaa1111', 60, ['laravel.trace.label' => 'x', 'db.query.n_plus_one.count' => 1]),
            ]]);
        },
        'tempo.test:3200/api/traces/*' => Http::response(mcpTracePayload(1, 'x')),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(ListTracesTool::class, ['label' => 'x'])
        ->assertOk()
        ->assertSee('"trace_id":"aaaa1111aaaa1111aaaa1111aaaa1111"')
        ->assertSee('"flags":["n_plus_one"]');

    TelemetryServer::tool(CompareTracesTool::class, ['a' => 'x', 'b' => 'aaaa1111aaaa1111aaaa1111aaaa1111'])->assertOk();

    Http::assertSent(fn (Request $request): bool => rawurldecode(requestQuery($request)['q'] ?? '') === '{ span.laravel.trace.label = "x" }');
});

it('tries an id-shaped reference as a label when no trace has that id', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/deadbeef*' => Http::response(['batches' => []]),
        'tempo.test:3200/api/search*' => Http::response(['traces' => [mcpSearchHit('aaaa1111aaaa1111aaaa1111aaaa1111', 60)]]),
        'tempo.test:3200/api/traces/aaaa1111*' => Http::response(mcpTracePayload(1, 'deadbeef00000000')),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(CompareTracesTool::class, ['a' => 'deadbeef00000000', 'b' => 'aaaa1111aaaa1111aaaa1111aaaa1111'])->assertOk();
});

it('reads the slow-query hint off the root from laravel-telemetry 3.2, searching only for older roots', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => function (Request $request) {
            $q = rawurldecode(requestQuery($request)['q'] ?? '');

            return Http::response(['traces' => str_contains($q, 'nestedSetParent') ? [
                mcpSearchHit('aaaa0000aaaa0000aaaa0000aaaa0000', 10, ['laravel.trace.label' => 'after', 'db.query.slow.count' => 2]),
                mcpSearchHit('bbbb0000bbbb0000bbbb0000bbbb0000', 20, ['laravel.trace.label' => 'after', 'db.query.slow.count' => 0]),
            ] : []]);
        },
    ]);

    TelemetryServer::tool(ListTracesTool::class, ['label' => 'after', 'has_flags' => true])
        ->assertOk()
        ->assertSee('"trace_id":"aaaa0000aaaa0000aaaa0000aaaa0000"')
        ->assertSee('"flags":["slow_query"]')
        ->assertDontSee('bbbb0000');

    $queries = collect(Http::recorded())->map(fn (array $pair): string => rawurldecode(requestQuery($pair[0])['q'] ?? ''));

    // The label is on every span from 3.2: the listing still asks for roots.
    expect($queries)->toContain('{ nestedSetParent < 0 && span.laravel.trace.label = "after" } | select(span.laravel.trace.label, span.http.response.status_code, span.db.query.count, span.db.query.n_plus_one.count, span.db.query.duplicate.count, span.db.query.slow.count, span.telemetry.overhead_ms)')
        // Every root counted its slow queries, so no slow-span search.
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'duration >='))->all())->toBe([]);
});

it('resolves a label to the newest root span carrying it', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [mcpSearchHit('aaaa1111aaaa1111aaaa1111aaaa1111', 60)]]),
        'tempo.test:3200/api/traces/*' => Http::response(mcpTracePayload(1, 'x')),
        'loki.test:3100/*' => Http::response(MCP_EMPTY_LOKI),
    ]);

    TelemetryServer::tool(CompareTracesTool::class, ['a' => 'x', 'b' => 'aaaa1111aaaa1111aaaa1111aaaa1111'])->assertOk();

    Http::assertSent(fn (Request $request): bool => rawurldecode(requestQuery($request)['q'] ?? '') === '{ nestedSetParent < 0 && span.laravel.trace.label = "x" }');
});
