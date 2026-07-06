<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\DuplicateQueries;
use Cbox\TelemetryUi\Cards\Builtin\FeatureChecks;
use Cbox\TelemetryUi\Cards\Builtin\HorizonOverview;
use Cbox\TelemetryUi\Cards\Builtin\LivewireSlow;
use Cbox\TelemetryUi\Cards\Builtin\RateLimits;
use Cbox\TelemetryUi\Cards\Builtin\WebVitals;
use Cbox\TelemetryUi\TraceDrawer;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A Prometheus instant-vector response. */
function promVector(array $results): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $results]];
}

/** A Prometheus range-matrix response. */
function promMatrix(array $results): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $results]];
}

it('renders pennant feature checks grouped by flag with unknown-flag warnings', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            if (str_contains($q, 'feature_unknown_total')) {
                return Http::response(promVector([
                    ['metric' => ['feature' => 'new-chekout'], 'value' => [1735689600, '7']],
                ]));
            }

            return Http::response(promVector([
                ['metric' => ['feature' => 'new-checkout', 'result' => 'active'], 'value' => [1735689600, '90']],
                ['metric' => ['feature' => 'new-checkout', 'result' => 'inactive'], 'value' => [1735689600, '10']],
            ]));
        },
    ]);

    Livewire::test(FeatureChecks::class)
        ->assertSee('new-checkout')
        ->assertSee('90%')                 // active share
        ->assertSee('unregistered flags')  // the typo'd flag warning
        ->assertSee('new-chekout');
});

it('charts rate limit rejections by limiter', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(promMatrix([
            ['metric' => ['limiter' => 'api'], 'values' => [[1735689600, '3'], [1735689660, '5']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(promVector([
            ['metric' => [], 'value' => [1735689600, '42']],
        ])),
    ]);

    Livewire::test(RateLimits::class)
        ->assertSee('Rate limiting')
        ->assertSee('Rejected')
        ->assertSee('42');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'rate_limit_exceeded_total');
    });
});

it('renders horizon worker gauges', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(promMatrix([
            ['metric' => ['supervisor' => 'supervisor-1'], 'values' => [[1735689600, '8'], [1735689660, '8']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(promVector([
            ['metric' => [], 'value' => [1735689600, '8']],
        ])),
    ]);

    Livewire::test(HorizonOverview::class)
        ->assertSee('Horizon workers')
        ->assertSee('Processes')
        ->assertSee('Paused');
});

it('lists slow livewire component spans with phase and method', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'dddd1111dddd1111dddd1111dddd1111', 'rootServiceName' => 'demo', 'rootTraceName' => 'POST /livewire/update', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 300, 'spanSets' => [['spans' => [
                    ['spanID' => 'l1', 'name' => 'livewire.call', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '250000000', 'attributes' => [
                        ['key' => 'livewire.component', 'value' => ['stringValue' => 'App\\Livewire\\Checkout']],
                        ['key' => 'livewire.method', 'value' => ['stringValue' => 'submit']],
                    ]],
                ]]]],
            ],
        ]),
    ]);

    Livewire::test(LivewireSlow::class)
        ->assertSee('App\\Livewire\\Checkout')
        ->assertSee('call')
        ->assertSee('submit')
        ->assertSeeHtml('data-row-trace="dddd1111dddd1111dddd1111dddd1111"');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        // The RE2 pattern escapes the dot: name =~ "livewire\\.(render|update|call)"
        return str_contains($q, 'livewire\\\\.(render|update|call)');
    });
});

it('aggregates core web vitals per page at p75 with threshold tones', function (): void {
    $span = fn (string $id, string $url, int $lcp, string $cls, int $inp): array => [
        'spanID' => $id, 'name' => 'web-vitals', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '1000000',
        'attributes' => [
            ['key' => 'http.url', 'value' => ['stringValue' => $url]],
            ['key' => 'web_vitals.lcp_ms', 'value' => ['intValue' => (string) $lcp]],
            ['key' => 'web_vitals.cls', 'value' => ['doubleValue' => $cls]],
            ['key' => 'web_vitals.inp_ms', 'value' => ['intValue' => (string) $inp]],
        ],
    ];

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => 'aaaa', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 5,
                    'spanSets' => [['spans' => [
                        $span('v1', 'https://app.test/orders', 2100, '0.05', 150),
                        $span('v2', 'https://app.test/orders', 5100, '0.32', 650),
                    ]]]],
            ],
        ]),
    ]);

    Livewire::test(WebVitals::class)
        ->assertSee('Core Web Vitals')
        ->assertSee('/orders')
        ->assertSee('p75 LCP')
        ->assertSee('5.1s'); // p75 lands on the slow view

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'name = "web-vitals"')
            && str_contains($q, 'web_vitals.lcp_ms');
    });
});

it('groups duplicate-query detections from loki by query text', function (): void {
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'demo',
                        'db_query_text' => 'select * from users where id = ?',
                        'db_namespace' => 'mysql',
                        'db_query_repeat_count' => '12',
                        'trace_id' => 'eeee1111eeee1111eeee1111eeee1111',
                    ],
                    'values' => [
                        ['1735689600000000000', 'db.query.duplicate_detected'],
                        ['1735689500000000000', 'db.query.duplicate_detected'],
                    ],
                ],
            ]],
        ]),
    ]);

    Livewire::test(DuplicateQueries::class)
        ->assertSee('select * from users where id = ?')
        ->assertSee('×12')
        ->assertSeeHtml('data-trace-id="eeee1111eeee1111eeee1111eeee1111"');
});

it('shows the cpu profile strip when a trace has a captured profile', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
                ]]],
            ]],
        ]),
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'demo',
                        'profile_top_functions' => json_encode([
                            ['name' => 'App\\Services\\ReportBuilder::build', 'percent' => 61.4, 'count' => 812],
                            ['name' => 'PDO::execute', 'percent' => 12.2, 'count' => 161],
                        ]),
                        'trace_id' => 'abc123abc123abc123abc123abc123ab',
                    ],
                    'values' => [['1735689601000000000', 'profile.captured']],
                ],
            ]],
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSee('GET /orders')
        ->assertSee('Profile')
        ->assertSee('App\\Services\\ReportBuilder::build')
        ->assertSee('61.4%');
});

it('renders span links as linked-trace rows in the waterfall', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'job App\\Jobs\\Ship attempt 2', 'kind' => 'SPAN_KIND_CONSUMER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000',
                        'links' => [['traceId' => 'ffff2222ffff2222ffff2222ffff2222', 'spanId' => 'b2', 'attributes' => [['key' => 'queue.retry', 'value' => ['boolValue' => true]]]]]],
                ]]],
            ]],
        ]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-trace', traceId: 'abc123abc123abc123abc123abc123ab')
        ->assertSee('linked trace')
        ->assertSeeHtml('data-trace-id="ffff2222ffff2222ffff2222ffff2222"');
});
