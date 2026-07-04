<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\AnalyticsBreakdown;
use Cbox\TelemetryUi\Cards\Builtin\AnalyticsOverview;
use Cbox\TelemetryUi\Cards\Builtin\AnalyticsPages;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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

    Livewire::test(AnalyticsOverview::class)
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

    Livewire::test(AnalyticsOverview::class)
        ->assertSee('50%')   // 1 of 2 sessions was single-page
        ->assertSee('30s');  // avg of 20s + 40s visible time
});

it('ranks top pages with distinct visitors', function (): void {
    fakePageViews();

    Livewire::test(AnalyticsPages::class)
        ->assertSee('/orders')
        ->assertSee('/checkout');
});

it('breaks visits down by referrer, country and device', function (): void {
    fakePageViews();

    Livewire::test(AnalyticsBreakdown::class)
        ->assertSee('google.com')      // www. stripped, query dropped
        ->assertSee('Direct / none')   // the entries without a referer
        ->assertSee('DK')
        ->assertSee('US')
        ->assertSee('mobile')
        ->assertSee('desktop');
});
