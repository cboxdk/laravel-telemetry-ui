<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\UnifiedErrors;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The Sentry-style behaviors of the unified errors list: first-seen looks
 * beyond the page period, NEW marks groups born in the last 24h, trends
 * are period-scoped sparklines, and rows are sortable.
 */
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
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'bbbb33334444', 'exception_type' => 'PaymentDeclined', 'exception_message' => 'fresh regression', 'enduser_id' => '7'],
                    'values' => [
                        [(string) (($now - 7200) * 1_000_000_000), 'exception'],
                        [(string) (($now - 60) * 1_000_000_000), 'exception'],
                    ],
                ],
                [
                    'stream' => ['service_name' => 'demo', 'exception_group' => 'bbbb33334444', 'exception_type' => 'PaymentDeclined', 'exception_message' => 'fresh regression', 'enduser_id' => '9'],
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

    Livewire::withQueryParams(['period' => '1h'])
        ->test(UnifiedErrors::class)
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

    $html = Livewire::withQueryParams(['period' => '1h'])->test(UnifiedErrors::class)->html();

    // NEW appears exactly once — only on the fresh group.
    expect(substr_count($html, '>NEW<'))->toBe(1);
});

it('counts distinct affected users per group', function (): void {
    fakeErrorRecords();

    $html = Livewire::withQueryParams(['period' => '1h'])->test(UnifiedErrors::class)->html();

    // PaymentDeclined hit users 7 and 9; the old group carries no user.
    expect($html)->toContain('Users');
    // Two distinct users on the fresh group.
    expect(preg_match('/PaymentDeclined.*?<td class="is-num">2<\/td>/s', $html))->toBe(1);
});

it('filters by text and source', function (): void {
    fakeErrorRecords();

    Livewire::withQueryParams(['period' => '1h'])
        ->test(UnifiedErrors::class)
        ->set('search', 'fresh regression')
        ->assertSee('PaymentDeclined')
        ->assertDontSee('RuntimeException')
        ->set('search', '')
        ->set('sourceFilter', 'frontend')
        ->assertSee('No errors match the filter');
});

it('sorts by first-seen when asked', function (): void {
    fakeErrorRecords();

    $html = Livewire::withQueryParams(['period' => '1h'])
        ->test(UnifiedErrors::class)
        ->set('sort', 'new')
        ->html();

    // The fresh group (born 2h ago) outranks the 5-day-old one.
    expect(strpos($html, 'PaymentDeclined'))->toBeLessThan(strpos($html, 'RuntimeException'));
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

    Livewire::withQueryParams(['period' => '1h'])
        ->test(UnifiedErrors::class)
        ->assertDontSee('StaleException')
        ->assertSee('No errors in this period');
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
                        'host_name' => 'web-3', 'enduser_id' => '7',
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

    $this->get('/telemetry-ui/error-detail?group=abc123def456')
        ->assertOk()
        ->assertSee('PaymentDeclined')            // header title
        ->assertSee('Card declined')              // header subtitle
        ->assertSee('All issues')                 // back link
        ->assertSee('Events')                     // trend card
        ->assertSee('Tags')                       // distributions card
        ->assertSee('web-3')                      // host distribution value
        ->assertSee('100%')                       // single host -> 100%
        ->assertSee('Latest occurrence')          // deep-dive card
        ->assertSee('#0 app/Checkout.php(42): charge()'); // stacktrace
});
