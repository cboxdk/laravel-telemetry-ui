<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\FrontendFetches;
use Cbox\TelemetryUi\Cards\Builtin\FrontendPages;
use Cbox\TelemetryUi\Cards\Builtin\TraceSearch;
use Cbox\TelemetryUi\Cards\Builtin\UnifiedErrors;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function errorSpan(string $group, string $type, string $message, bool $browser): array
{
    $attrs = [
        ['key' => 'exception.group', 'value' => ['stringValue' => $group]],
        ['key' => 'exception.type', 'value' => ['stringValue' => $type]],
        ['key' => 'exception.message', 'value' => ['stringValue' => $message]],
    ];

    if ($browser) {
        $attrs[] = ['key' => 'browser', 'value' => ['boolValue' => true]];
    }

    return $attrs;
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

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'span.browser != true'));
});

it('unifies frontend and backend errors into one list grouped by fingerprint', function (): void {
    // Same fingerprint g1 seen in the browser AND the backend -> one full-stack
    // row; a backend-only RuntimeException stays its own row.
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '1111111111111111aaaaaaaaaaaaaaaa', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 5,
                    'spanSets' => [['spans' => [['spanID' => 'a1', 'name' => 'exception', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '0', 'attributes' => errorSpan('g1', 'TypeError', 'Cannot read foo of undefined', true)]]]]],
                ['traceID' => '2222222222222222bbbbbbbbbbbbbbbb', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /x', 'startTimeUnixNano' => '1735689500000000000', 'durationMs' => 5,
                    'spanSets' => [['spans' => [['spanID' => 'b1', 'name' => 'exception', 'startTimeUnixNano' => '1735689500000000000', 'durationNanos' => '0', 'attributes' => errorSpan('g1', 'TypeError', 'Cannot read foo of undefined', false)]]]]],
                ['traceID' => '3333333333333333cccccccccccccccc', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /y', 'startTimeUnixNano' => '1735689400000000000', 'durationMs' => 5,
                    'spanSets' => [['spans' => [['spanID' => 'c1', 'name' => 'exception', 'startTimeUnixNano' => '1735689400000000000', 'durationNanos' => '0', 'attributes' => errorSpan('g2', 'RuntimeException', 'boom', false)]]]]],
            ],
        ]),
    ]);

    Livewire::test(UnifiedErrors::class)
        ->assertSee('TypeError')
        ->assertSee('RuntimeException')
        ->assertSeeHtml('full-stack')                                  // g1 seen in both browser + backend
        ->assertSeeHtml('data-row-trace="1111111111111111aaaaaaaaaaaaaaaa"') // representative = most recent occurrence
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
