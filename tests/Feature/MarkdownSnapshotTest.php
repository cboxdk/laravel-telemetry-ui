<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Analysis\Snapshot\ExceptionSnapshot;
use Cbox\TelemetryUi\Analysis\Snapshot\MarkdownSnapshot;
use Cbox\TelemetryUi\Analysis\Snapshot\StackFrames;
use Cbox\TelemetryUi\Analysis\Snapshot\TraceSnapshot;
use Cbox\TelemetryUi\Analysis\TraceDiagnosis;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * The "copy as Markdown" snapshots: pure functions of a trace (or an error
 * group's report) already read, so most of this needs no backend fakes.
 */
function snapSpan(string $id, string $name, array $attributes = [], int $ms = 2, int $at = 10, ?string $parent = 'root', SpanKind $kind = SpanKind::Client, bool $error = false): Span
{
    $start = 1_735_689_600_000_000_000 + $at * 1_000_000;

    return new Span($id, $parent, $name, 'shop', $kind, $start, $start + $ms * 1_000_000, $attributes, $error);
}

const SNAP_USERS = 'select * from `users` where `users`.`id` = ? limit 1';

/** A checkout that runs an N+1, then fails on a deadlock. */
function snapTrace(int $loop = 6): Trace
{
    $spans = [
        snapSpan('root', 'POST /checkout', [
            'http.route' => '/checkout', 'http.response.status_code' => 500, 'laravel.trace.label' => 'before-fix',
            'db.query.count' => $loop + 2, 'db.query.n_plus_one.count' => 1,
        ], 400, 0, null, SpanKind::Server, true),
        snapSpan('c1', 'cache.get', ['cache.key' => 'cart:7'], 1, 2),
    ];

    for ($i = 1; $i <= $loop; $i++) {
        $spans[] = snapSpan("u{$i}", 'db.query', [
            'db.system.name' => 'mysql',
            'db.query.text' => SNAP_USERS,
            'laravel.db.connection' => 'mysql',
            'laravel.db.query.shape' => 'bbbb111111111111',
            // A binding the emitter must never have sent; were it ever on a
            // span, the snapshot still must not read it.
            'db.query.parameter.0' => 'secret-customer-email@example.com',
            ...($i >= 3 ? ['laravel.db.query.repeat' => 'n_plus_one', 'code.file.path' => 'app/Http/Controllers/CheckoutController.php', 'code.line.number' => 42, 'code.function.name' => 'App\\Http\\Controllers\\CheckoutController::store'] : []),
        ], 2, 10 + $i * 3);
    }

    $spans[] = snapSpan('ins', 'db.query', [
        'db.system.name' => 'mysql', 'db.query.text' => 'insert into `orders` (`user_id`) values (?)', 'exception.type' => 'Illuminate\\Database\\QueryException',
    ], 5, 300, error: true);
    $spans[] = snapSpan('after', 'db.query', ['db.system.name' => 'mysql', 'db.query.text' => 'rollback'], 1, 320);

    return new Trace('abc123abc123abc123abc123abc123ab', $spans);
}

/** @return list<array<string, mixed>> */
function snapExceptions(): array
{
    return [[
        'group' => 'abc123def456', 'type' => 'Illuminate\\Database\\QueryException', 'message' => 'Deadlock found when trying to get lock',
        'file' => 'app/Repositories/OrderRepository.php', 'line' => 88, 'source' => 'backend', 'match' => 'trace',
        'stacktrace' => implode("\n", [
            '#0 /srv/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(812): runQueryCallback()',
            '#1 /srv/app/app/Repositories/OrderRepository.php(88): Illuminate\\Database\\Connection->insert()',
            '#2 /srv/app/app/Http/Controllers/CheckoutController.php(51): App\\Repositories\\OrderRepository->create()',
            '#3 /srv/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): store()',
            '#4 {main}',
        ]),
        'code' => "  87| \$order = new Order;\n> 88| \$order->save();\n  89| return \$order;",
    ]];
}

function snapMarkdown(?Trace $trace = null, array $exceptions = [], int $maxBytes = MarkdownSnapshot::MAX_BYTES): MarkdownSnapshot
{
    $trace ??= snapTrace();
    $headlines = array_map(static function (array $e): array {
        unset($e['stacktrace'], $e['code']);

        return $e;
    }, $exceptions);

    return TraceSnapshot::build($trace, TraceDiagnosis::analyze($trace, $headlines), $exceptions, $maxBytes);
}

it('titles a trace snapshot with what it was and how it ended', function (): void {
    $md = snapMarkdown(exceptions: snapExceptions())->markdown;

    expect($md)
        ->toStartWith('# Trace: POST /checkout — failed, HTTP 500, 400 ms')
        ->toContain('- **Service:** shop')
        ->toContain('- **Status:** error (HTTP 500)')
        ->toContain('- **Started:** 2025-01-01T00:00:00Z')
        ->toContain('- **Label:** before-fix')
        ->toContain('- **Trace id:** `abc123abc123abc123abc123abc123ab`')
        ->toContain('/telemetry-ui/traces/abc123abc123abc123abc123abc123ab');
});

it('lists the flags errors first, then the N+1 with the line that ran it', function (): void {
    $md = snapMarkdown(exceptions: snapExceptions())->markdown;

    $flags = substr($md, (int) strpos($md, '## Flags'), (int) strpos($md, '## Time by category') - (int) strpos($md, '## Flags'));

    expect($flags)
        ->toContain('## Flags (')
        ->toContain('- **error** · exception — Illuminate\\\\Database\\\\QueryException: Deadlock found when trying to get lock — at `app/Repositories/OrderRepository.php:88`')
        // Backticks in a message are escaped, never opened as code spans.
        ->toContain('- **warning** · db — N+1: 6× select * from \\`users\\`')
        ->and(strpos($flags, '**error**'))->toBeLessThan(strpos($flags, '**warning**'));
});

it('gives the time per category and the root totals', function (): void {
    $md = snapMarkdown()->markdown;

    expect($md)
        ->toContain("## Time by category\n\n| category | time | share |")
        ->toContain('| db | ')
        ->toContain('| cache | 1 ms | <1% |')
        ->toContain('## Totals')
        ->toContain('- Queries: 8')
        ->toContain('- N+1 shapes (app-counted): 1');
});

it('shows each query group with its full SQL in a fence it cannot close, and its origin', function (): void {
    $md = snapMarkdown()->markdown;

    expect($md)
        ->toContain('### N+1 — 6 runs')
        ->toContain('- **Origin:** `app/Http/Controllers/CheckoutController.php:42` in `App\\Http\\Controllers\\CheckoutController::store`')
        ->toContain('**Shape:** `bbbb111111111111`')
        ->toContain("```sql\n".SNAP_USERS."\n```");
});

it('never includes query bindings, even when a span carries them', function (): void {
    expect(snapMarkdown(exceptions: snapExceptions())->markdown)
        ->not->toContain('secret-customer-email@example.com')
        ->not->toContain('db.query.parameter');
});

it('shows the exceptions with their app frames and the recorded source excerpt', function (): void {
    $md = snapMarkdown(exceptions: snapExceptions())->markdown;

    expect($md)
        ->toContain('### 1. `Illuminate\\Database\\QueryException`: Deadlock found when trying to get lock')
        ->toContain('- **At:** `app/Repositories/OrderRepository.php:88`')
        ->toContain('- **Group (fingerprint):** `abc123def456`')
        ->toContain('/telemetry-ui/errors/abc123def456')
        // App frames only, relative to the project root; framework frames left out.
        ->toContain('App frames (2 of 2 app frames, 4 in all; framework frames left out):')
        ->toContain("#1 app/Repositories/OrderRepository.php(88): Illuminate\\Database\\Connection->insert()\n#2 app/Http/Controllers/CheckoutController.php(51)")
        ->not->toContain('Connection.php(812)')
        ->toContain("```php\n  87| \$order = new Order;\n> 88| \$order->save();");
});

it('leaves the stacktrace and source out when the record carries none', function (): void {
    $exceptions = snapExceptions();
    $exceptions[0]['stacktrace'] = '';
    $exceptions[0]['code'] = '';

    expect(snapMarkdown(exceptions: $exceptions)->markdown)
        ->toContain('### 1. `Illuminate\\Database\\QueryException`')
        ->not->toContain('App frames')
        ->not->toContain('Source around the throw');
});

it('shows what led up to the first failing span, and nothing after it', function (): void {
    $md = snapMarkdown()->markdown;
    $lead = substr($md, (int) strpos($md, '## What led up to it'));
    $lead = substr($lead, 0, (int) strpos($lead, '## Query groups'));

    expect($lead)
        ->toContain('The last 7 spans that started before the first failing span, `db.query` at +300 ms:')
        ->toContain('| +2 ms | cache.get · key: cart:7 | cache | 1 ms |')
        ->toContain('| +300 ms | **failed:** insert into \\`orders\\` (\\`user_id\\`) values (?) (Illuminate\\\\Database\\\\QueryException) | db | 5 ms |')
        ->not->toContain('rollback');
});

it('leaves out what led up to it on a trace that did not fail', function (): void {
    $trace = new Trace('t-ok', [
        snapSpan('root', 'GET /', [], 50, 0, null, SpanKind::Server),
        snapSpan('q', 'db.query', ['db.system.name' => 'mysql', 'db.query.text' => 'select 1'], 2, 5),
    ]);

    expect(snapMarkdown($trace)->markdown)
        ->toContain('# Trace: GET / — ok, 50 ms')
        ->toContain('Nothing flagged')
        ->not->toContain('## What led up to it')
        ->not->toContain('## Exceptions')
        ->not->toContain('## Query groups');
});

it('stays under the cap and says what it cut', function (): void {
    $exceptions = snapExceptions();
    $exceptions[0]['code'] = str_repeat("  1| filler line of source code\n", 200);

    $snapshot = snapMarkdown(snapTrace(40), $exceptions, maxBytes: 2_500);

    expect($snapshot->bytes())->toBeLessThanOrEqual(2_500)
        ->and($snapshot->truncated)->toBeTrue()
        ->and($snapshot->omitted)->toContain('Query groups')
        ->and($snapshot->markdown)
        ->toContain('> **Truncated** to stay under 2 KB:')
        ->toContain('"Query groups"')
        ->toStartWith('# Trace: POST /checkout');

    // Every code fence it opened is closed again.
    expect(preg_match_all('/^```/m', $snapshot->markdown) % 2)->toBe(0);
});

it('is not marked truncated when everything fits, and ends with where it came from', function (): void {
    $snapshot = snapMarkdown(exceptions: snapExceptions());

    expect($snapshot->truncated)->toBeFalse()
        ->and($snapshot->omitted)->toBe([])
        ->and($snapshot->bytes())->toBeLessThanOrEqual(MarkdownSnapshot::MAX_BYTES)
        ->and($snapshot->markdown)->toContain('query bindings are never added');
});

it('fences text holding backticks with a longer fence', function (): void {
    expect(MarkdownSnapshot::fence('a ``` b', 'sql'))->toBe("````sql\na ``` b\n````")
        ->and(MarkdownSnapshot::code('`users`'))->toBe('`` `users` ``')
        ->and(MarkdownSnapshot::cell('a | b'))->toBe('a \\| b');
});

it('closes a code fence the cap cut through', function (): void {
    $snapshot = MarkdownSnapshot::assemble([
        ['Title', '# T'],
        ['Code', "## Code\n\n".MarkdownSnapshot::fence(str_repeat("line\n", 400), 'text')],
        ['Tail', '## Tail'],
    ], maxBytes: 1_200);

    expect($snapshot->omitted)->toBe(['Code', 'Tail'])
        ->and(preg_match_all('/^```/m', $snapshot->markdown) % 2)->toBe(0)
        ->and($snapshot->markdown)->toContain('"Code" was cut short; left out: "Tail"');
});

it('reads the app frames out of a PHP stacktrace', function (): void {
    $frames = StackFrames::app(snapExceptions()[0]['stacktrace'], 1);

    expect($frames)->toBe([
        'frames' => ['#1 app/Repositories/OrderRepository.php(88): Illuminate\\Database\\Connection->insert()'],
        'total' => 4,
        'app' => 2,
    ]);
});

it('snapshots an error group with source, app frames and a link to its trace snapshot', function (): void {
    $snapshot = ExceptionSnapshot::build('abc123def456', [
        'occurrences' => [],
        'stats' => ['count' => 3, 'sampled' => false, 'firstSeen' => '2 hours ago', 'lastSeen' => '5 minutes ago', 'source' => 'backend', 'users' => 1],
        'detail' => [
            'type' => 'App\\Exceptions\\PaymentDeclined', 'message' => 'Card declined by issuer',
            'file' => 'app/Services/Checkout.php', 'line' => 42,
            'stacktrace' => "#0 /srv/app/app/Services/Checkout.php(42): charge()\n#1 /srv/app/vendor/laravel/framework/src/Kernel.php(10): handle()\n#2 {main}",
            'source' => "  41| \$card = \$request->card();\n> 42| throw new PaymentDeclined();",
            'environment' => 'production', 'release' => 'v2.4.1', 'host' => 'web-3',
        ],
        'request' => ['traceId' => '1111111111111111aaaaaaaaaaaaaaaa', 'origin' => 'POST /orders', 'method' => 'POST', 'route' => '/orders', 'status' => '500', 'user' => '7'],
        'suspect' => null,
        'releases' => [['release' => 'v2.4.1', 'count' => 3]],
    ]);

    expect($snapshot->markdown)
        ->toStartWith('# PaymentDeclined: Card declined by issuer')
        ->toContain('- **Group (fingerprint):** `abc123def456`')
        ->toContain('- **Occurrences:** 3 in the last '.ErrorGroupReport::LOOKBACK_DAYS.' days')
        ->toContain("## App frames\n\n1 of 1 app frames, 2 in all")
        ->toContain('#0 app/Services/Checkout.php(42): charge()')
        ->toContain('## Source around the throw (`app/Services/Checkout.php:42`)')
        ->toContain('> 42| throw new PaymentDeclined();')
        ->toContain('- **Trace:** `1111111111111111aaaaaaaaaaaaaaaa` — http://localhost/telemetry-ui/traces/1111111111111111aaaaaaaaaaaaaaaa')
        ->toContain('- **Trace snapshot (Markdown):** http://localhost/telemetry-ui/api/v2/traces/1111111111111111aaaaaaaaaaaaaaaa/markdown?format=text')
        ->and($snapshot->truncated)->toBeFalse();
});

it('makes untrusted text inert: no code spans, links or HTML, and no way to escape the escaping', function (): void {
    expect(MarkdownSnapshot::text('pay [here](https://evil.test) <img src=x onerror=alert(1)> `rm` \\` end'))
        ->toBe('pay \\[here\\](https://evil.test) \\<img src=x onerror=alert(1)\\> \\`rm\\` \\\\\\` end');
});

it('keeps the footer inside the cap, newlines included', function (): void {
    $title = '# '.str_repeat('a', 100);
    $footer = str_repeat('f', 50);
    // Exactly enough for title + "\n\n" + footer + "\n"…
    $fits = MarkdownSnapshot::assemble([['Title', $title]], $footer, maxBytes: strlen($title) + strlen($footer) + 3);
    // …and one byte short.
    $short = MarkdownSnapshot::assemble([['Title', $title]], $footer, maxBytes: strlen($title) + strlen($footer) + 2);

    expect($fits->markdown)->toEndWith($footer."\n")
        ->and($fits->bytes())->toBeLessThanOrEqual(strlen($title) + strlen($footer) + 3)
        ->and($short->markdown)->not->toContain($footer)
        ->and($short->bytes())->toBeLessThanOrEqual(strlen($title) + strlen($footer) + 2);
});
