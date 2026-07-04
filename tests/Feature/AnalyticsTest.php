<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\AnalyticsBreakdown;
use Cbox\TelemetryUi\Cards\Builtin\AnalyticsOverview;
use Cbox\TelemetryUi\Cards\Builtin\AnalyticsPages;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    // Analytics page-view events: line body is the event name; the visit's
    // dimensions arrive as per-entry structured metadata (value[2]).
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [[
                'stream' => ['service_name' => 'cbox-web', 'telemetry_stream' => 'analytics'],
                'values' => [
                    ['1735689600000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/orders', 'http_request_header_referer' => 'https://www.google.com/search?q=x', 'client_geo_country' => 'DK', 'device_type' => 'mobile', 'user_agent_name' => 'Chrome']],
                    ['1735689601000000000', 'analytics.page_view', ['session_id' => 's1', 'url_path' => '/orders', 'client_geo_country' => 'DK', 'device_type' => 'mobile']],
                    ['1735689602000000000', 'analytics.page_view', ['session_id' => 's2', 'url_path' => '/checkout', 'client_geo_country' => 'US', 'device_type' => 'desktop']],
                ],
            ]]],
        ]),
    ]);
});

it('shows visit headline stats with cookieless unique visitors', function (): void {
    Livewire::test(AnalyticsOverview::class)
        ->assertSee('Page views')
        ->assertSee('Unique visitors')
        ->assertSee('/orders')   // top page
        ->assertSee('DK');       // top country
});

it('ranks top pages with distinct visitors', function (): void {
    Livewire::test(AnalyticsPages::class)
        ->assertSee('/orders')
        ->assertSee('/checkout');
});

it('breaks visits down by referrer, country and device', function (): void {
    Livewire::test(AnalyticsBreakdown::class)
        ->assertSee('google.com')      // www. stripped, query dropped
        ->assertSee('Direct / none')   // the entries without a referer
        ->assertSee('DK')
        ->assertSee('US')
        ->assertSee('mobile')
        ->assertSee('desktop');
});
