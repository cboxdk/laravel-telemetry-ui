<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\FrontendFetches;
use Cbox\TelemetryUi\Cards\Builtin\FrontendPages;
use Cbox\TelemetryUi\Cards\Builtin\TraceSearch;
use Cbox\TelemetryUi\Cards\Builtin\UnifiedErrors;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function errorSpan(string $type, string $message, string $file, int $line): array
{
    return [
        ['key' => 'exception.type', 'value' => ['stringValue' => $type]],
        ['key' => 'exception.message', 'value' => ['stringValue' => $message]],
        ['key' => 'exception.file', 'value' => ['stringValue' => $file]],
        ['key' => 'exception.line', 'value' => ['intValue' => (string) $line]],
        ['key' => 'browser', 'value' => ['boolValue' => true]],
    ];
}

it('scopes trace search to browser spans when the source is frontend', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    Livewire::test(TraceSearch::class)->set('source', 'frontend');

    // Browser/RUM spans are tagged span.browser=true by the ingest proxy.
    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'span.browser = true'));
});

it('excludes browser spans when the source is backend', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    Livewire::test(TraceSearch::class)->set('source', 'backend');

    // Not span.browser != true — TraceQL can't evaluate missing attributes,
    // so that filter silently matched nothing on real data.
    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'kind = server'));
});

it('unifies frontend and backend errors into one list grouped by fingerprint', function (): void {
    // Backend errors come from the structured exception records in Loki;
    // browser errors come from Tempo exception spans, fingerprinted read-side
    // with the same algorithm — so a JS error and a backend record from the
    // same site collapse into one full-stack row, while a backend-only
    // RuntimeException stays its own row.
    $shared = ExceptionFingerprint::compute('TypeError', 'checkout.js', 10);
    $now = time();

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => ['service_name' => 'cbox-web', 'exception_group' => $shared, 'exception_type' => 'TypeError', 'exception_message' => 'Cannot read foo of undefined'],
                    'values' => [[(string) (($now - 300) * 1_000_000_000), 'exception']],
                ],
                [
                    'stream' => ['service_name' => 'cbox-web', 'exception_group' => 'aaaa11112222', 'exception_type' => 'RuntimeException', 'exception_message' => 'boom'],
                    'values' => [[(string) (($now - 400) * 1_000_000_000), 'exception']],
                ],
            ]],
        ]),
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '1111111111111111aaaaaaaaaaaaaaaa', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => (string) (($now - 200) * 1_000_000_000), 'durationMs' => 5,
                    'spanSets' => [['spans' => [['spanID' => 'a1', 'name' => 'exception', 'startTimeUnixNano' => (string) (($now - 200) * 1_000_000_000), 'durationNanos' => '0', 'attributes' => errorSpan('TypeError', 'Cannot read foo of undefined', 'checkout.js', 10)]]]]],
            ],
        ]),
    ]);

    Livewire::test(UnifiedErrors::class)
        ->assertSee('TypeError')
        ->assertSee('RuntimeException')
        ->assertSeeHtml('full-stack')                          // shared fingerprint seen in both browser + backend
        ->assertSeeHtml('error-detail')                        // row opens the issue's show page
        ->assertSeeHtml('group='.$shared)
        ->assertSeeHtml('tui-badge-web');
});

it('aggregates real-user page performance by path', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'aaaa', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 500, 'spanSets' => [['spans' => [
                    ['spanID' => 'p1', 'name' => 'document.load', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '500000000', 'attributes' => [
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://app.test/orders?ref=x']],
                        ['key' => 'browser.ttfb_ms', 'value' => ['intValue' => '100']],
                        ['key' => 'browser.dom_interactive_ms', 'value' => ['intValue' => '200']],
                    ]],
                    ['spanID' => 'p2', 'name' => 'document.load', 'startTimeUnixNano' => '1735689601000000000', 'durationNanos' => '600000000', 'attributes' => [
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://app.test/orders']],
                        ['key' => 'browser.ttfb_ms', 'value' => ['intValue' => '140']],
                        ['key' => 'browser.dom_interactive_ms', 'value' => ['intValue' => '240']],
                    ]],
                ]]]],
                ['traceID' => 'bbbb', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => '1735689602000000000', 'durationMs' => 400, 'spanSets' => [['spans' => [
                    ['spanID' => 'p3', 'name' => 'document.load', 'startTimeUnixNano' => '1735689602000000000', 'durationNanos' => '400000000', 'attributes' => [
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://app.test/checkout']],
                        ['key' => 'browser.ttfb_ms', 'value' => ['intValue' => '90']],
                        ['key' => 'browser.dom_interactive_ms', 'value' => ['intValue' => '180']],
                    ]],
                ]]]],
            ],
        ]),
    ]);

    Livewire::test(FrontendPages::class)
        ->assertSee('Page loads')      // stats strip
        ->assertSee('Avg TTFB')
        ->assertSee('/orders')         // grouped by path, query dropped
        ->assertSee('/checkout')
        ->assertDontSee('ref=x');      // path only, no query string
});

it('lists failed browser fetches grouped by url', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'cccc1111cccc1111cccc1111cccc1111', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /x', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 5, 'spanSets' => [['spans' => [
                    ['spanID' => 'f1', 'name' => 'fetch GET', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '5000000', 'attributes' => [
                        ['key' => 'http.url', 'value' => ['stringValue' => 'https://api.stripe.com/v1/charges']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '503']],
                    ]],
                ]]]],
            ],
        ]),
    ]);

    Livewire::test(FrontendFetches::class)
        ->assertSee('api.stripe.com/v1/charges')
        ->assertSee('503')
        ->assertSeeHtml('data-row-trace="cccc1111cccc1111cccc1111cccc1111"');
});
