<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\RequestReport;
use Cbox\TelemetryUi\Analysis\TraceComparison;
use Cbox\TelemetryUi\Analysis\TraceDiagnosis;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * A span starting $at ms into the trace and lasting $ms.
 *
 * @param  array<string, mixed>  $attributes
 */
function diagSpan(string $id, string $name, array $attributes = [], int $ms = 2, int $at = 10, ?string $parent = 'root', SpanKind $kind = SpanKind::Client, bool $error = false): Span
{
    $start = 1_735_689_600_000_000_000 + $at * 1_000_000;

    return new Span($id, $parent, $name, 'shop', $kind, $start, $start + $ms * 1_000_000, $attributes, $error);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function diagRoot(array $attributes = [], int $ms = 1000, bool $error = false): Span
{
    return diagSpan('root', 'GET /orders', ['http.route' => '/orders', ...$attributes], $ms, 0, null, SpanKind::Server, $error);
}

/**
 * $n runs of one statement shape, the emitter's repeat marker from the
 * threshold-crossing (third) run onward.
 *
 * @return list<Span>
 */
function diagLoop(string $prefix, string $shape, string $sql, int $n, string $repeat, array $origin = [], int $ms = 2): array
{
    $spans = [];

    for ($i = 1; $i <= $n; $i++) {
        $spans[] = diagSpan("{$prefix}{$i}", 'db.query', [
            'db.system.name' => 'mysql',
            'db.query.text' => $sql,
            'laravel.db.connection' => 'mysql',
            'laravel.db.query.shape' => $shape,
            ...($i >= 3 ? ['laravel.db.query.repeat' => $repeat, ...$origin] : []),
        ], $ms, 10 + $i * 3);
    }

    return $spans;
}

const DIAG_USERS = 'select * from `users` where `users`.`id` = ? limit 1';
const DIAG_ORIGIN = ['code.file.path' => 'app/Http/Controllers/OrderController.php', 'code.line.number' => 42, 'code.function.name' => 'App\\Http\\Controllers\\OrderController::index'];

it('groups an N+1 by shape, counts every run of it and names the line that ran it', function (): void {
    $trace = new Trace('t1', [
        diagRoot(['db.query.count' => 13, 'db.query.time_ms' => 26.0, 'db.query.n_plus_one.count' => 1, 'db.query.duplicate.count' => 0, 'telemetry.overhead_ms' => 1.2]),
        diagSpan('q0', 'db.query', ['db.system.name' => 'mysql', 'db.query.text' => 'select * from `orders`', 'laravel.db.query.shape' => 'aaaa000000000000'], 4, 5),
        ...diagLoop('u', 'bbbb111111111111', DIAG_USERS, 12, 'n_plus_one', DIAG_ORIGIN),
    ]);

    $d = TraceDiagnosis::analyze($trace);

    expect($d->totals)->toMatchArray([
        'queries' => 13,
        'query_time_ms' => 26.0,
        'n_plus_one_shapes' => 1,
        'duplicate_queries_distinct' => 0,
        'telemetry_overhead_ms' => 1.2,
        'query_shapes' => 2,
    ]);

    $flag = collect($d->flags)->firstWhere('key', 'n_plus_one:bbbb111111111111');

    // Twelve runs of the shape, though only ten carry the marker.
    expect($flag['level'])->toBe('warning')
        ->and($flag['section'])->toBe('db')
        ->and($flag['message'])->toBe('N+1: 12× '.DIAG_USERS)
        ->and($flag['origin'])->toBe('app/Http/Controllers/OrderController.php:42')
        ->and($flag['detail']['function'])->toBe('App\\Http\\Controllers\\OrderController::index')
        ->and($flag['detail']['flagged_n_plus_one'])->toBe(10);

    // A shape that ran once is not flagged.
    expect(collect($d->flags)->pluck('key'))->not->toContain('n_plus_one:aaaa000000000000');
});

it('puts errors first: exception records, a 5xx, failed jobs', function (): void {
    $trace = new Trace('t2', [
        diagRoot(['http.response.status_code' => 500], error: true),
        diagSpan('j1', 'App\\Jobs\\Ship', ['laravel.job.class' => 'App\\Jobs\\Ship', 'exception.type' => 'RuntimeException'], 20, 50, kind: SpanKind::Consumer, error: true),
        ...diagLoop('u', 'bbbb111111111111', DIAG_USERS, 3, 'n_plus_one'),
    ]);

    $d = TraceDiagnosis::analyze($trace, [[
        'group' => 'g1', 'type' => 'QueryException', 'message' => 'Deadlock found',
        'file' => 'app/Repositories/OrderRepository.php', 'line' => 88, 'source' => 'backend', 'match' => 'trace',
    ]]);

    expect(collect($d->flags)->pluck('level')->all())->toBe(['error', 'error', 'error', 'warning'])
        ->and($d->flags[0]['message'])->toBe('QueryException: Deadlock found')
        ->and($d->flags[0]['origin'])->toBe('app/Repositories/OrderRepository.php:88')
        ->and($d->flags[1]['message'])->toBe('Responded 500')
        ->and($d->flags[2]['message'])->toBe('Job App\\Jobs\\Ship failed: RuntimeException')
        // The failing root is the consequence of the flags above, not a fourth error.
        ->and(collect($d->flags)->pluck('key'))->not->toContain('span:GET /orders')
        ->and($d->identity['status'])->toBe('error')
        ->and($d->identity['http_status'])->toBe(500);
});

it('reports an identical repeat as a lower bound of the shape, and a shape can be both', function (): void {
    $trace = new Trace('t3', [
        diagRoot(),
        ...diagLoop('d', 'cccc222222222222', 'select * from `settings` where `key` = ?', 5, 'duplicate'),
    ]);

    $flag = collect(TraceDiagnosis::analyze($trace)->flags)->firstWhere('key', 'duplicate:cccc222222222222');

    // 3 marked + the 2 runs before the threshold.
    expect($flag['message'])->toBe('Duplicate (identical SQL and bindings): ≥5× of 5 runs: select * from `settings` where `key` = ?');

    $both = new Trace('t4', [
        diagRoot(),
        ...diagLoop('a', 'dddd333333333333', DIAG_USERS, 4, 'n_plus_one'),
        diagSpan('b1', 'db.query', ['db.query.text' => DIAG_USERS, 'laravel.db.query.shape' => 'dddd333333333333', 'laravel.db.query.repeat' => 'duplicate'], 2, 80),
    ]);

    expect(collect(TraceDiagnosis::analyze($both)->flags)->pluck('key')->all())
        ->toContain('n_plus_one:dddd333333333333', 'duplicate:dddd333333333333');
});

it('flags slow query shapes and high telemetry overhead against the configured thresholds', function (): void {
    $trace = new Trace('t5', [
        diagRoot(['telemetry.overhead_ms' => 30.0], 200),
        diagSpan('s1', 'db.query', ['db.query.text' => 'select * from `reports`', 'laravel.db.query.shape' => 'eeee444444444444', ...DIAG_ORIGIN], 140, 10),
        diagSpan('s2', 'db.query', ['db.query.text' => 'select 1', 'laravel.db.query.shape' => 'ffff555555555555'], 20, 160),
    ]);

    $d = TraceDiagnosis::analyze($trace, [], ['slow_query_ms' => 100, 'overhead_ms' => 5, 'overhead_ratio' => 0.05]);
    $keys = collect($d->flags)->pluck('key')->all();

    expect($keys)->toBe(['slow:eeee444444444444', 'overhead'])
        ->and($d->flags[0]['message'])->toBe('Slow query (140ms): select * from `reports`')
        ->and($d->flags[0]['origin'])->toBe('app/Http/Controllers/OrderController.php:42')
        ->and($d->flags[1]['message'])->toBe('Telemetry overhead 30ms (15% of the trace)');

    // Raise the bar and neither is a problem any more.
    $calm = TraceDiagnosis::analyze($trace, [], ['slow_query_ms' => 500, 'overhead_ms' => 50]);
    expect($calm->flags)->toBe([]);
});

it('says when the app counted slow queries that no span read here shows', function (): void {
    $trace = new Trace('t9', [
        diagRoot(['db.query.slow.count' => 2]),
        diagSpan('q1', 'db.query', ['db.query.text' => 'select 1', 'laravel.db.query.shape' => 'ffff555555555555'], 20, 10),
    ]);

    $d = TraceDiagnosis::analyze($trace, [], ['slow_query_ms' => 100]);

    expect($d->totals['slow_queries'])->toBe(2)
        ->and(collect($d->flags)->pluck('key')->all())->toBe(['slow:root'])
        ->and($d->flags[0]['message'])->toStartWith('2 slow queries counted by the app');

    // When a span does show it, the per-shape flag says it better.
    $shown = TraceDiagnosis::analyze($trace, [], ['slow_query_ms' => 10]);
    expect(collect($shown->flags)->pluck('key')->all())->toBe(['slow:ffff555555555555']);
});

it('splits the time by category without counting a nested span twice', function (): void {
    $trace = new Trace('t6', [
        diagRoot(ms: 100),
        diagSpan('q1', 'db.query', ['db.system.name' => 'mysql', 'db.query.text' => 'select 1'], 10, 5),
        diagSpan('c1', 'cache.hit', ['cache.key' => 'k'], 2, 20),
        diagSpan('v1', 'view.render', ['view.name' => 'orders.index'], 30, 30, kind: SpanKind::Internal),
        // A query run while rendering the view is the view's time.
        diagSpan('q2', 'db.query', ['db.system.name' => 'mysql', 'db.query.text' => 'select 2'], 10, 35, 'v1'),
        diagSpan('h1', 'GET api.test', ['server.address' => 'api.test'], 20, 70),
    ]);

    expect(TraceDiagnosis::analyze($trace)->timing)->toBe([
        'db' => 10.0, 'cache' => 2.0, 'http' => 20.0, 'view' => 30.0, 'queue' => 0.0, 'other' => 38.0,
    ]);
});

it('falls back to statement text on traces from before query shapes, without guessing the kind', function (): void {
    $legacy = new Trace('t7', [
        diagRoot(['db.query.duplicate.count' => 1]),
        diagSpan('l1', 'db.query', ['db.query.text' => DIAG_USERS], 2, 10),
        diagSpan('l2', 'db.query', ['db.query.text' => DIAG_USERS], 2, 20),
        diagSpan('l3', 'db.query', ['db.query.text' => DIAG_USERS], 2, 30),
    ]);

    $flags = TraceDiagnosis::analyze($legacy)->flags;

    expect($flags)->toHaveCount(1)
        ->and($flags[0]['message'])->toBe('Repeated (older data: N+1 or identical): 3× '.DIAG_USERS)
        ->and($flags[0]['key'])->toStartWith('repeated:text:');
});

it('compares two traces: totals, categories, shapes and the flags on one side only', function (): void {
    $before = TraceDiagnosis::analyze(new Trace('before', [
        diagRoot(['db.query.count' => 13, 'db.query.n_plus_one.count' => 1, 'laravel.trace.label' => 'before'], 400),
        diagSpan('q0', 'db.query', ['db.query.text' => 'select * from `orders`', 'laravel.db.query.shape' => 'aaaa000000000000'], 4, 5),
        ...diagLoop('u', 'bbbb111111111111', DIAG_USERS, 12, 'n_plus_one', DIAG_ORIGIN),
    ]));

    $after = TraceDiagnosis::analyze(new Trace('after', [
        diagRoot(['db.query.count' => 2, 'db.query.n_plus_one.count' => 0, 'laravel.trace.label' => 'after'], 120),
        diagSpan('q0', 'db.query', ['db.query.text' => 'select * from `orders`', 'laravel.db.query.shape' => 'aaaa000000000000'], 4, 5),
        diagSpan('q1', 'db.query', ['db.query.text' => 'select * from `users` where `users`.`id` in (?, ?)', 'laravel.db.query.shape' => '9999000000000000'], 3, 15),
    ]));

    $diff = TraceComparison::between($before, $after);

    expect($diff['a']['label'])->toBe('before')
        ->and($diff['duration_ms'])->toMatchArray(['a' => 400.0, 'b' => 120.0, 'delta' => -280.0, 'change_pct' => -70.0])
        ->and($diff['totals']['queries'])->toBe(['a' => 13, 'b' => 2, 'delta' => -11])
        ->and($diff['totals']['n_plus_one_shapes'])->toBe(['a' => 1, 'b' => 0, 'delta' => -1])
        ->and($diff['queries'])->toMatchArray(['appeared' => 1, 'disappeared' => 1, 'changed' => 0, 'unchanged' => 1])
        ->and($diff['queries']['disappeared_top'][0])->toMatchArray(['count' => 12, 'origin' => 'app/Http/Controllers/OrderController.php:42'])
        ->and(collect($diff['flags']['only_a'])->pluck('message')->all())->toBe(['N+1: 12× '.DIAG_USERS])
        ->and($diff['flags']['only_b'])->toBe([]);
});

it('marks repeats and origins in the trace story', function (): void {
    $report = RequestReport::from(new Trace('t8', [
        diagRoot(['laravel.trace.label' => 'after-fix', 'db.query.n_plus_one.count' => 1, 'db.query.duplicate.count' => 0]),
        ...diagLoop('u', 'bbbb111111111111', DIAG_USERS, 4, 'n_plus_one', DIAG_ORIGIN),
    ]));

    expect($report['db']['repeats'])->toBe([
        ['kind' => 'n_plus_one', 'query' => DIAG_USERS, 'count' => 4, 'origin' => 'app/Http/Controllers/OrderController.php:42'],
    ])
        ->and(collect($report['db']['items'])->pluck('origin')->filter()->unique()->values()->all())->toBe(['app/Http/Controllers/OrderController.php:42'])
        ->and($report['request']['label'])->toBe('after-fix')
        ->and(collect($report['totals'])->pluck('label')->all())->toContain('N+1 queries', 'duplicate queries');
});

/**
 * $n sequential GETs of one endpoint with a different id each, the way
 * laravel-telemetry 3.6 stamps them: the shape and template on every call,
 * the N+1 marker from the threshold-crossing (third) call onward, the origin
 * on the crossing call.
 *
 * @return list<Span>
 */
function diagCalls(string $prefix, int $n, bool $shaped = true): array
{
    $spans = [];

    for ($i = 1; $i <= $n; $i++) {
        $spans[] = diagSpan("{$prefix}{$i}", 'GET api.example.com', [
            'http.request.method' => 'GET',
            'server.address' => 'api.example.com',
            'url.full' => 'https://api.example.com/users/'.(16 + $i),
            'http.response.status_code' => 200,
            ...($shaped ? ['laravel.http.client.shape' => 'cccc222222222222', 'url.template' => '/users/{id}'] : []),
            ...($shaped && $i >= 3 ? ['laravel.http.client.repeat' => 'n_plus_one'] : []),
            ...($shaped && $i === 3 ? DIAG_ORIGIN : []),
        ], 20, 100 + $i * 25);
    }

    return $spans;
}

it('groups outgoing calls by shape and flags an N+1 of them with the line that made them', function (): void {
    $d = TraceDiagnosis::analyze(new Trace('h1', [
        diagRoot(['http.client.n_plus_one.count' => 1]),
        diagSpan('p1', 'POST api.example.com', ['http.request.method' => 'POST', 'server.address' => 'api.example.com', 'url.full' => 'https://api.example.com/orders', 'laravel.http.client.shape' => 'dddd333333333333', 'url.template' => '/orders'], 30, 50),
        ...diagCalls('c', 5),
    ]));

    expect($d->totals)->toMatchArray(['http_n_plus_one_shapes' => 1, 'http_call_shapes' => 2])
        ->and($d->httpShapes['cccc222222222222'])->toMatchArray([
            'text' => 'GET api.example.com/users/{id}',
            'example' => 'https://api.example.com/users/17',
            'count' => 5,
            'nPlusOne' => 3,
            'origin' => 'app/Http/Controllers/OrderController.php:42',
        ]);

    $flags = collect($d->flags)->where('section', 'http')->values();

    // Every call of the shape counted, not only the three marked ones.
    expect($flags)->toHaveCount(1)
        ->and($flags[0]['key'])->toBe('http_n_plus_one:cccc222222222222')
        ->and($flags[0]['message'])->toBe('N+1 outgoing calls: 5× GET api.example.com/users/{id}')
        ->and($flags[0]['origin'])->toBe('app/Http/Controllers/OrderController.php:42')
        ->and($flags[0]['detail'])->toMatchArray(['url_template' => '/users/{id}', 'count' => 5, 'flagged_n_plus_one' => 3]);

    $report = RequestReport::from(new Trace('h1', [diagRoot(['http.client.n_plus_one.count' => 1]), ...diagCalls('c', 5)]));

    expect($report['outgoingRepeats'])->toBe([
        ['kind' => 'n_plus_one', 'query' => 'GET api.example.com/users/{id}', 'count' => 5, 'origin' => 'app/Http/Controllers/OrderController.php:42'],
    ])
        ->and(collect($report['totals'])->pluck('label')->all())->toContain('N+1 outgoing calls');
});

it('leaves outgoing calls from before call shapes alone, guessing nothing', function (): void {
    $d = TraceDiagnosis::analyze(new Trace('h2', [diagRoot(), ...diagCalls('c', 6, shaped: false)]));

    expect($d->httpShapes)->toBe([])
        ->and($d->totals)->not->toHaveKeys(['http_call_shapes', 'http_n_plus_one_shapes'])
        ->and(collect($d->flags)->where('section', 'http')->all())->toBe([])
        ->and(RequestReport::from(new Trace('h2', [diagRoot(), ...diagCalls('c', 6, shaped: false)]))['outgoingRepeats'])->toBe([]);
});

it('says when the app counted N+1 outgoing calls that no span read here carries', function (): void {
    $flags = TraceDiagnosis::analyze(new Trace('h3', [diagRoot(['http.client.n_plus_one.count' => 2])]))->flags;

    expect($flags)->toHaveCount(1)
        ->and($flags[0]['key'])->toBe('http_n_plus_one:root')
        ->and($flags[0]['message'])->toBe('2 N+1 outgoing call shapes counted by the app, none marked among the spans read here');
});
