<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The Sentry-style behaviors of the unified errors list: first-seen looks
 * beyond the page period, NEW marks groups born in the last 24h, trends
 * are period-scoped sparklines, and rows are sortable.
 */

/**
 * The unified-errors panel payload for a 1h page period, plus extra params.
 *
 * @param  array<string, string>  $params
 */
function errorsPanel(array $params = []): TestResponse
{
    return test()->getJson(panelUrl('unified-errors', ['period' => '1h', ...$params]))->assertOk();
}

/**
 * The row whose error cell shows the given exception type.
 *
 * @return array<string, mixed>|null
 */
function errorRow(TestResponse $response, string $type): ?array
{
    foreach ((array) $response->json('rows') as $row) {
        if (($row['error']['v'] ?? null) === $type) {
            return $row;
        }
    }

    return null;
}
function fakeErrorRecords(): void
{
    $now = time();

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                // An OLD group: born 5 days ago, still failing right now.
                [
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'aaaa11112222', 'exception_type' => 'RuntimeException', 'exception_message' => 'old faithful'],
                    'values' => [
                        [(string) (($now - 5 * 86400) * 1_000_000_000), 'exception'],
                        [(string) (($now - 300) * 1_000_000_000), 'exception'],
                        [(string) (($now - 120) * 1_000_000_000), 'exception'],
                    ],
                ],
                // A NEW group: born two hours ago, still failing in-period —
                // and hitting two distinct users.
                [
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'bbbb33334444', 'exception_type' => 'PaymentDeclined', 'exception_message' => 'fresh regression', 'user_id' => '7'],
                    'values' => [
                        [(string) (($now - 7200) * 1_000_000_000), 'exception'],
                        [(string) (($now - 60) * 1_000_000_000), 'exception'],
                    ],
                ],
                [
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'bbbb33334444', 'exception_type' => 'PaymentDeclined', 'exception_message' => 'fresh regression', 'user_id' => '9'],
                    'values' => [
                        [(string) (($now - 30) * 1_000_000_000), 'exception'],
                    ],
                ],
            ]],
        ]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);
}

it('computes first-seen beyond the page period and marks fresh groups NEW', function (): void {
    fakeErrorRecords();

    errorsPanel()
        ->assertJsonPath('kind', 'table')
        ->assertSee('RuntimeException')
        ->assertSee('5 days ago')        // first seen survives the 1h period
        ->assertSee('PaymentDeclined')
        ->assertSee('NEW');              // born 2h ago -> new

    // The search window extends past the 1h page period.
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'loki.test')) {
            return false;
        }
        $start = (int) (requestQuery($request)['start'] ?? 0);

        return $start > 0 && $start < (time() - 6 * 86400) * 1_000_000_000;
    });
});

it('does not mark an old group as NEW', function (): void {
    fakeErrorRecords();

    $response = errorsPanel();

    // NEW appears exactly once — only on the fresh group.
    expect(substr_count((string) $response->getContent(), '"NEW"'))->toBe(1)
        ->and(errorRow($response, 'PaymentDeclined')['error']['badge'] ?? null)->toBe('NEW')
        ->and(errorRow($response, 'RuntimeException')['error'])->not->toHaveKey('badge');
});

it('counts distinct affected users per group', function (): void {
    fakeErrorRecords();

    $response = errorsPanel()->assertJsonFragment(['key' => 'users', 'label' => 'Users', 'align' => 'right']);

    // PaymentDeclined hit users 7 and 9; the old group carries no user.
    expect(errorRow($response, 'PaymentDeclined')['users']['v'] ?? null)->toBe('2')
        ->and(errorRow($response, 'RuntimeException')['users']['v'] ?? null)->toBe('—');
});

it('links each row to its error group', function (): void {
    fakeErrorRecords();

    expect(errorRow(errorsPanel(), 'PaymentDeclined')['_link'] ?? null)
        ->toBe(['to' => 'error', 'group' => 'bbbb33334444']);
});

it('exposes sort, search and source as panel controls', function (): void {
    fakeErrorRecords();

    $controls = collect((array) errorsPanel(['err_sort' => 'last'])->json('controls'))->keyBy('param');

    expect($controls->keys()->all())->toEqualCanonicalizing(['err_q', 'err_source', 'err_sort'])
        ->and($controls['err_sort']['value'])->toBe('last')
        ->and($controls['err_q']['type'])->toBe('search');
});

it('filters by text and source', function (): void {
    fakeErrorRecords();

    errorsPanel(['err_q' => 'fresh regression'])
        ->assertSee('PaymentDeclined')
        ->assertDontSee('RuntimeException');

    errorsPanel(['err_source' => 'frontend'])
        ->assertJsonCount(0, 'rows')
        ->assertJsonPath('empty', 'No errors match the filter.');
});

it('sorts by first-seen when asked', function (): void {
    fakeErrorRecords();

    // The fresh group (born 2h ago) outranks the 5-day-old one.
    errorsPanel(['err_sort' => 'new'])
        ->assertJsonPath('rows.0.error.v', 'PaymentDeclined')
        ->assertJsonPath('rows.1.error.v', 'RuntimeException');
});

it('only lists groups active within the page period', function (): void {
    $now = time();

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                // Seen 3 days ago only — outside a 1h period: no row.
                [
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'cccc55556666', 'exception_type' => 'StaleException', 'exception_message' => 'long gone'],
                    'values' => [[(string) (($now - 3 * 86400) * 1_000_000_000), 'exception']],
                ],
            ]],
        ]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    errorsPanel()
        ->assertDontSee('StaleException')
        ->assertJsonCount(0, 'rows')
        ->assertJsonPath('empty', 'No errors in this period. 🎉');
});

it('renders the full issue page: header, trend, tags and deep-dive', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    $now = time();

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => function ($request) use ($now) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            if (str_contains($q, '|~')) { // annotation markers: none
                return Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]);
            }

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'checkout', 'exception_group' => 'abc123def456',
                        'exception_type' => 'PaymentDeclined', 'exception_message' => 'Card declined',
                        'exception_file' => 'app/Checkout.php', 'exception_line' => '42',
                        'exception_stacktrace' => '#0 app/Checkout.php(42): charge()',
                        'deployment_environment_name' => 'production', 'deployment_id' => 'v9.1.0',
                        'host_name' => 'web-3', 'user_id' => '7',
                    ],
                    'values' => [
                        [(string) (($now - 300) * 1_000_000_000), 'exception'],
                        [(string) (($now - 200) * 1_000_000_000), 'exception'],
                    ],
                ],
            ]]]);
        },
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    // The issue page is a registered page whose panels the SPA fetches one
    // by one, all scoped to ?group=.
    $this->getJson(apiUrl('pages/error-detail'))
        ->assertOk()
        ->assertJsonPath('panels.*.id', ['error-group-header', 'error-group-trend', 'error-group-sidebar', 'error-group-tags', 'error-group-detail']);

    $panel = fn (string $id) => $this->getJson(panelUrl($id, ['group' => 'abc123def456']))->assertOk();

    $panel('error-group-header')
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', 'PaymentDeclined')          // header title
        ->assertJsonPath('subtitle', 'Card declined')         // header subtitle
        ->assertJsonPath('back.label', '← All issues');       // back link

    $panel('error-group-trend')->assertJsonPath('title', 'Events');   // trend card

    $panel('error-group-tags')
        ->assertJsonPath('title', 'Tags')                     // distributions card
        ->assertSee('web-3')                                  // host distribution value
        ->assertSee('100%');                                  // single host -> 100%

    $panel('error-group-detail')
        ->assertJsonPath('title', 'Latest occurrence')        // deep-dive card
        ->assertSee('#0 app/Checkout.php(42): charge()');     // stacktrace
});
