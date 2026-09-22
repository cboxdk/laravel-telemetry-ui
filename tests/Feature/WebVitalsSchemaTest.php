<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Both browser SDKs feed Web Vitals: laravel-telemetry's `web-vitals` span
 * (one per page, `web_vitals.*`) and @cboxdk/telemetry-browser's
 * `browser.web_vital` markers (one per metric, `web_vital.name/value`).
 */
beforeEach(function (): void {
    $now = time() - 60;

    Http::fake(function (Request $request) use ($now) {
        $q = (string) (requestQuery($request)['q'] ?? '');

        if (str_contains($q, 'browser.web_vital')) {
            return Http::response(['traces' => [
                tempoHit('t1', 'browser.web_vital', $now, 0, ['browser' => true, 'url.path' => '/checkout', 'web_vital.name' => 'LCP', 'web_vital.value' => 3100.0]),
                tempoHit('t1', 'browser.web_vital', $now, 0, ['browser' => true, 'url.path' => '/checkout', 'web_vital.name' => 'TTFB', 'web_vital.value' => 420.0]),
                tempoHit('t2', 'browser.web_vital', $now, 0, ['browser' => true, 'url.path' => '/checkout', 'web_vital.name' => 'CLS', 'web_vital.value' => 0.02]),
            ]]);
        }

        if (str_contains($q, 'web-vitals')) {
            return Http::response(['traces' => [
                tempoHit('t3', 'web-vitals', $now, 0, ['browser' => true, 'http.url' => 'https://shop.test/?x=1', 'web_vitals.lcp_ms' => 1200, 'web_vitals.inp_ms' => 90]),
            ]]);
        }

        return Http::response(['traces' => []]);
    });
});

it('reads telemetry-browser marker vitals and legacy web-vitals spans into one table', function (): void {
    $response = $this->getJson(panelUrl('web-vitals', ['period' => '1h']))->assertOk();

    $rows = collect($response->json('parts.1.rows'))->keyBy(fn (array $r): string => $r['path']['v']);

    expect($rows->keys()->all())->toEqualCanonicalizing(['/checkout', '/'])
        ->and($rows['/checkout']['lcp']['v'])->toBe('3.1s')
        ->and($rows['/checkout']['lcp']['tone'])->toBe('warn')
        ->and($rows['/checkout']['ttfb']['v'])->toBe('420ms')
        ->and($rows['/checkout']['views']['v'])->toBe('2')
        ->and($rows['/']['lcp']['v'])->toBe('1.2s')
        ->and($rows['/']['lcp']['tone'])->toBe('ok');
});

it('scopes the page-detail vitals to one path', function (): void {
    $this->getJson(panelUrl('page-detail-performance', ['period' => '1h', 'path' => '/checkout']))
        ->assertOk()
        ->assertSee('p75 LCP')
        ->assertSee('3.1s');
});
