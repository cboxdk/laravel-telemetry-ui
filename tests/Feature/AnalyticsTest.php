<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * A Loki streams response. Analytics page-view events: line body is the event
 * name; the visit's dimensions arrive as per-entry structured metadata.
 *
 * @param  list<array{string, string, array<string, string>}>  $values
 */
function lokiStream(array $values): array
{
    return [
        'status' => 'success',
        'data' => ['resultType' => 'streams', 'result' => [[
            'stream' => ['service_name' => 'cbox-web', 'telemetry_stream' => 'analytics'],
            'values' => $values,
        ]]],
    ];
}

function fakePageViews(): void
{
    Http::fake(['loki.test:3100/loki/api/v1/query_range*' => Http::response(lokiStream([
        ['1735689600000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/orders', 'http_request_header_referer' => 'https://www.google.com/search?q=x', 'client_geo_country' => 'DK', 'device_type' => 'mobile', 'user_agent_name' => 'Chrome']],
        ['1735689601000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/orders', 'client_geo_country' => 'DK', 'device_type' => 'mobile']],
        ['1735689602000000000', 'analytics.page_view', ['session_id' => 's2', 'url_path' => '/checkout', 'client_geo_country' => 'US', 'device_type' => 'desktop']],
    ]))]);
}

it('shows visit headline stats with cookieless unique visitors', function (): void {
    fakePageViews();

    $this->getJson(panelUrl('analytics-overview', ['period' => '1h']))
        ->assertOk()
        ->assertSee('Page views')
        ->assertSee('Unique visitors')
        ->assertSee('Bounce rate')
        ->assertSee('Avg engagement');
});

it('computes bounce rate and average engagement time', function (): void {
    // Route the two queries: page views (s1 twice, s2 once → 50% single-view)
    // and engagement events with visible_time_ms (avg 30s).
    Http::fake(function ($request) {
        if (str_contains(rawurldecode($request->url()), 'analytics.engagement')) {
            return Http::response(lokiStream([
                ['1735689600000000000', 'analytics.engagement', ['session_id' => 's1', 'visible_time_ms' => '20000']],
                ['1735689601000000000', 'analytics.engagement', ['session_id' => 's2', 'visible_time_ms' => '40000']],
            ]));
        }

        return Http::response(lokiStream([
            ['1735689600000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/a']],
            ['1735689601000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/b']],
            ['1735689602000000000', 'analytics.page_view', ['session_id' => 's2', 'url_path' => '/a']],
        ]));
    });

    $this->getJson(panelUrl('analytics-overview', ['period' => '1h']))
        ->assertOk()
        ->assertSee('50%')   // 1 of 2 sessions was single-page
        ->assertSee('30s');  // avg of 20s + 40s visible time
});

it('ranks top pages with distinct visitors as bars drilling into the page', function (): void {
    fakePageViews();

    $this->getJson(panelUrl('analytics-pages', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'bars')
        ->assertJsonPath('items.0.label', '/orders')
        ->assertJsonPath('items.0.value', 2)
        ->assertJsonPath('items.0.sub', '1 visitor')
        ->assertJsonPath('items.0.link', ['to' => 'entity', 'type' => 'path', 'value' => '/orders'])
        ->assertJsonPath('items.1.label', '/checkout');
});

it('breaks visits down by referrer, country and device', function (): void {
    fakePageViews();

    $this->getJson(panelUrl('analytics-breakdown', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'composite')
        ->assertJsonPath('parts.0.title', 'Channels') // derived, first-class dimension
        ->assertJsonPath('controls.0.param', 'dimension')
        ->assertSee('google.com')      // www. stripped, query dropped
        ->assertSee('Direct / none')   // the entries without a referer
        ->assertSee('DK')
        ->assertSee('US')
        ->assertSee('mobile')
        ->assertSee('desktop')
        ->assertSee('Organic search'); // google referrer → Organic
});

it('narrows the breakdown to one dimension via the picker', function (): void {
    fakePageViews();

    $this->getJson(panelUrl('analytics-breakdown', ['period' => '1h', 'dimension' => 'countries']))
        ->assertOk()
        ->assertJsonCount(1, 'parts')
        ->assertJsonPath('parts.0.title', 'Countries')
        ->assertJsonPath('parts.0.items.0.label', 'DK')
        ->assertJsonPath('controls.0.value', 'countries');
});

it('shows a single empty state on Campaigns until UTM capture is on', function (): void {
    fakePageViews(); // no utm_* labels

    $this->getJson(panelUrl('analytics-campaigns', ['period' => '1h']))
        ->assertOk()
        ->assertSee('TELEMETRY_ANALYTICS_UTM')
        ->assertJsonCount(0, 'parts'); // no per-dimension columns
});

it('breaks visits down by campaign, source and medium when UTM is captured', function (): void {
    Http::fake(['loki.test:3100/loki/api/v1/query_range*' => Http::response(lokiStream([
        ['1735689600000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/', 'analytics_utm_campaign' => 'spring-sale', 'analytics_utm_source' => 'newsletter', 'analytics_utm_medium' => 'email']],
        ['1735689601000000000', 'analytics.page_view', ['session_id' => 's2', 'url_path' => '/', 'analytics_utm_campaign' => 'spring-sale', 'analytics_utm_source' => 'google', 'analytics_utm_medium' => 'cpc']],
    ]))]);

    $this->getJson(panelUrl('analytics-campaigns', ['period' => '1h']))
        ->assertOk()
        ->assertDontSee('TELEMETRY_ANALYTICS_UTM')
        ->assertJsonPath('parts.0.title', 'Campaigns')
        ->assertJsonPath('parts.0.items.0.label', 'spring-sale')
        ->assertSee('newsletter')
        ->assertSee('cpc');
});

it('reports a backend failure on the panel instead of throwing', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('boom', 500)]);

    $this->getJson(panelUrl('analytics-pages', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'bars')
        ->assertJsonPath('items', [])
        ->assertJsonStructure(['error']);
});
