<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

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

it('renders a page detail header scoped to the url path', function (): void {
    $this->getJson(panelUrl('page-detail-header', ['period' => '1h', 'path' => '/blog/x']))
        ->assertOk()
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', '/blog/x')            // header title is the concrete path
        ->assertJsonPath('stats.0.label', 'Views')
        ->assertJsonPath('stats.0.value', '2')
        ->assertJsonPath('drill.page', 'analytics')     // the back link
        ->assertJsonPath('drill.label', '← Analytics');
});

it('scopes trace queries to the one url path', function (): void {
    $this->getJson(panelUrl('page-detail-traces', ['period' => '1h', 'path' => '/blog/x']))
        ->assertOk()
        ->assertJsonPath('rows.0._link', ['to' => 'trace', 'id' => '1111111111111111aaaaaaaaaaaaaaaa']);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        return str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'span.url.path = "/blog/x"');
    });
});

it('scopes the analytics log query to the url_path label', function (): void {
    $this->getJson(panelUrl('page-detail-traffic', ['period' => '1h', 'path' => '/blog/x']))
        ->assertOk()
        ->assertJsonPath('kind', 'composite')
        ->assertJsonPath('parts.0.kind', 'chart')
        ->assertJsonPath('parts.2.title', 'Countries')
        ->assertSee('DK');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'loki')) {
            return false;
        }

        return str_contains(rawurldecode(requestQuery($request)['query'] ?? ''), 'url_path="/blog/x"');
    });
});

it('shows the page\'s real-user navigation timings', function (): void {
    $this->getJson(panelUrl('page-detail-performance', ['period' => '1h', 'path' => '/blog/x']))
        ->assertOk()
        ->assertJsonPath('kind', 'composite')
        ->assertJsonPath('parts.0.title', 'Navigation timings')
        ->assertJsonPath('parts.0.items.0.label', 'Page loads')
        ->assertJsonPath('parts.0.items.0.value', '1');
});

it('shows a clean empty state when the page has no browser errors', function (): void {
    $this->getJson(panelUrl('page-detail-errors', ['period' => '1h', 'path' => '/blog/x']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonPath('rows', [])
        ->assertSee('No errors on this page');
});

it('keeps the page detail page out of the sidebar nav', function (): void {
    // v1 asserted the rendered sidebar; the nav now comes from the page
    // registry, where page-detail is a hidden child of Analytics.
    $meta = app(TelemetryUiManager::class)->pages()['page-detail'];

    expect($meta['hidden'] ?? false)->toBeTrue()
        ->and($meta['parent'] ?? null)->toBe('analytics');
});

it('points analytics top-page rows at the page detail, not a trace search', function (): void {
    $this->getJson(panelUrl('analytics-pages', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('items.0.label', '/blog/x')
        ->assertJsonPath('items.0.link', ['to' => 'entity', 'type' => 'path', 'value' => '/blog/x'])
        ->assertDontSee('"traces"', false); // no longer a pre-filtered trace search
});

it('points frontend page rows at the page detail, not a trace search', function (): void {
    $this->getJson(panelUrl('frontend-pages', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('parts.0.kind', 'stats')
        ->assertJsonPath('parts.1.rows.0.path.v', '/blog/x')
        ->assertJsonPath('parts.1.rows.0._link', ['to' => 'entity', 'type' => 'path', 'value' => '/blog/x'])
        ->assertDontSee('"traces"', false);
});
