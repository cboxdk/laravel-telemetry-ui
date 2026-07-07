<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\AnalyticsPages;
use Cbox\TelemetryUi\Cards\Builtin\FrontendPages;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * A Loki streams response of analytics page-view events for one path.
 *
 * @param  list<array{string, string, array<string, string>}>  $values
 */
function pageViewStream(array $values): array
{
    return [
        'status' => 'success',
        'data' => ['resultType' => 'streams', 'result' => [[
            'stream' => ['service_name' => 'cbox-web', 'telemetry_stream' => 'analytics'],
            'values' => $values,
        ]]],
    ];
}

/**
 * A Tempo search hit for a `document.load` (RUM) span on /blog/x — enough for
 * the frontend / RUM cards to render a page load.
 */
function docLoadTrace(): array
{
    $now = time();

    return [
        'traceID' => '1111111111111111aaaaaaaaaaaaaaaa',
        'rootServiceName' => 'cbox-web',
        'rootTraceName' => 'document.load',
        'startTimeUnixNano' => (string) ($now * 1_000_000_000),
        'durationMs' => 120,
        'spanSets' => [['spans' => [[
            'spanID' => 'a1',
            'name' => 'document.load',
            'startTimeUnixNano' => (string) ($now * 1_000_000_000),
            'durationNanos' => '120000000',
            'attributes' => [
                ['key' => 'http.url', 'value' => ['stringValue' => 'https://cbox.dk/blog/x']],
                ['key' => 'browser.ttfb_ms', 'value' => ['doubleValue' => 40.0]],
                ['key' => 'browser.dom_interactive_ms', 'value' => ['doubleValue' => 90.0]],
            ],
        ]]]],
    ];
}

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    // Http::fake merges stub callbacks (first match wins), so the specific
    // /api/search stub must come BEFORE the tempo catch-all.
    Http::fake([
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tempo.test:3200/api/search*' => Http::response(['traces' => [docLoadTrace()]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/loki/api/v1/query_range*' => Http::response(pageViewStream([
            ['1735689600000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/blog/x', 'client_geo_country' => 'DK', 'device_type' => 'mobile']],
            ['1735689601000000000', 'analytics.page_view', ['session_id' => 's2', 'url_path' => '/blog/x', 'client_geo_country' => 'US', 'device_type' => 'desktop']],
        ])),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
});

it('renders a purpose-built page detail page scoped to the url path', function (): void {
    $this->get('/telemetry-ui/page-detail?path=/blog/x')
        ->assertOk()
        ->assertSee('/blog/x')       // header title is the concrete path
        ->assertSee('Analytics');    // the back link
});

it('scopes trace queries to the one url path', function (): void {
    $this->get('/telemetry-ui/page-detail?path=/blog/x')->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        return str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'span.url.path = "/blog/x"');
    });
});

it('scopes the analytics log query to the url_path label', function (): void {
    $this->get('/telemetry-ui/page-detail?path=/blog/x')->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'loki')) {
            return false;
        }

        return str_contains(rawurldecode(requestQuery($request)['query'] ?? ''), 'url_path="/blog/x"');
    });
});

it('keeps the page detail page out of the sidebar nav', function (): void {
    $this->get('/telemetry-ui/analytics')
        ->assertOk()
        ->assertDontSeeHtml('>Page</a>');
});

it('points analytics top-page rows at the page detail, not a trace search', function (): void {
    Livewire::test(AnalyticsPages::class)
        ->assertSee('/blog/x')
        ->assertSeeHtml('page-detail')      // the row drills into the page's own detail
        ->assertDontSeeHtml('page=traces'); // no longer a pre-filtered trace search
});

it('points frontend page rows at the page detail, not a trace search', function (): void {
    Livewire::test(FrontendPages::class)
        ->assertSee('/blog/x')
        ->assertSeeHtml('page-detail')      // the row drills into the page's own detail
        ->assertDontSeeHtml('page=traces'); // no longer a pre-filtered trace search
});
