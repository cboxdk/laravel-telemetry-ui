<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\Period;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Generic fakes shaped like the real backends, so every card can render.
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => ['class' => '2xx', 'operation' => 'hit', 'queue' => 'default', 'state' => 'used', 'channel' => 'mail', 'server_address' => 'api.test', 'direction' => 'receive', 'period' => '1m'], 'values' => [[1735689600, '5'], [1735689660, '7']]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query?*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['class' => '2xx', 'http_route' => '/orders', 'http_request_method' => 'GET', 'job_name' => 'App\\Jobs\\Ship', 'queue' => 'default', 'command' => 'queue:work', 'task' => 'backup', 'exception' => 'RuntimeException', 'operation' => 'hit', 'server_address' => 'api.test'], 'value' => [1735689600, '42']],
            ]],
        ]),
        // Page detection reads the metric-name index, so the fake backend has
        // to hold a name per detectable family or those pages 404 here.
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response([
            'status' => 'success',
            'data' => allMetricNames(),
        ]),
        'prometheus.test:9090/api/v1/label/*' => Http::response([
            'status' => 'success',
            'data' => ['telemetry-demo'],
        ]),
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [[
                'traceID' => 'abc123abc123abc123abc123abc123ab',
                'rootServiceName' => 'demo',
                'rootTraceName' => 'GET /orders',
                'startTimeUnixNano' => '1735689600000000000',
                'durationMs' => 87,
                'spanSets' => [[
                    'spans' => [[
                        'spanID' => 'aaaa000000000001',
                        'name' => 'db.query',
                        'startTimeUnixNano' => '1735689600000000000',
                        'durationNanos' => '87000000',
                        'attributes' => [
                            ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from orders']],
                            ['key' => 'user.id', 'value' => ['intValue' => '7']],
                            ['key' => 'user.guard', 'value' => ['stringValue' => 'web']],
                        ],
                    ]],
                ]],
            ]],
        ]),
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                ['stream' => ['service_name' => 'demo', 'level' => 'error'], 'values' => [['1735689600000000000', 'Something failed trace=abc123abc123abc123abc123abc123ab']]],
            ]],
        ]),
    ]);
});

/** Every built-in page slug, including hidden detail pages. */
function builtinPages(): array
{
    return array_keys(app(TelemetryUiManager::class)->pages());
}

it('serves every built-in page and every panel on it as typed json', function (string $page): void {
    $response = $this->getJson(apiUrl('pages/'.$page))
        ->assertOk()
        ->assertJsonPath('page', $page);

    foreach ((array) $response->json('panels') as $panel) {
        $data = $this->getJson(panelUrl($panel['id'], ['_page' => $page]))
            ->assertOk()
            ->json();

        expect($data['kind'] ?? null)->toBeString("panel {$panel['id']} on {$page} has no kind");
    }
})->with([
    'dashboard', 'traces', 'requests', 'jobs', 'queues', 'autoscale', 'commands', 'schedule',
    'exceptions', 'queries', 'cache', 'storage', 'livewire', 'features',
    'horizon', 'reverb', 'outgoing', 'mail',
    'statamic-cache', 'statamic-stache', 'statamic-glide', 'statamic-forms',
    'statamic-content', 'statamic-inventory',
    'analytics', 'frontend', 'users', 'logs', 'system', 'hosts',
]);

it('serves every hidden detail page with its entity param', function (string $page, array $params): void {
    $response = $this->getJson(apiUrl('pages/'.$page))->assertOk();

    foreach ((array) $response->json('panels') as $panel) {
        $data = $this->getJson(panelUrl($panel['id'], [...$params, '_page' => $page]))->assertOk()->json();

        expect($data['kind'] ?? null)->toBeString("panel {$panel['id']} on {$page} has no kind");
    }
})->with([
    'request' => ['request-detail', ['route' => '/orders']],
    'job' => ['job-detail', ['job' => 'App\\Jobs\\Ship']],
    'queue' => ['queue-detail', ['queue' => 'default']],
    'exception' => ['exception-detail', ['exception' => 'RuntimeException']],
    'query' => ['query-detail', ['dbq' => 'select * from orders']],
    'outgoing' => ['outgoing-detail', ['host' => 'api.test']],
    'host' => ['host-detail', ['host' => 'web-1']],
    'page' => ['page-detail', ['path' => '/orders']],
]);

it('covers every registered page in the smoke list', function (): void {
    // A new built-in page must be added to one of the datasets above.
    expect(builtinPages())->toEqualCanonicalizing([
        'dashboard', 'traces', 'requests', 'request-detail', 'jobs', 'job-detail', 'queues', 'queue-detail',
        'autoscale', 'horizon', 'commands', 'schedule', 'exceptions', 'exception-detail', 'error-detail',
        'queries', 'query-detail', 'cache', 'storage', 'livewire', 'features', 'reverb', 'outgoing',
        'outgoing-detail', 'mail', 'analytics', 'page-detail', 'frontend', 'hosts', 'host-detail', 'users',
        'logs', 'system', 'statamic-cache', 'statamic-stache', 'statamic-glide', 'statamic-forms',
        'statamic-content', 'statamic-inventory',
    ]);
});

it('serves the dashboard panels in every period', function (string $period): void {
    foreach (app(TelemetryUiManager::class)->panels('dashboard') as $panel) {
        $this->getJson(panelUrl($panel::id(), ['period' => $period]))->assertOk()->assertJsonStructure(['kind']);
    }
})->with(array_map(fn (Period $period): string => $period->value, Period::cases()));

it('drill-links dashboard panels through to their pages, but not on their own page', function (): void {
    $this->getJson(panelUrl('jobs-overview'))
        ->assertOk()
        ->assertJsonPath('drill.to', 'page')
        ->assertJsonPath('drill.page', 'jobs')
        ->assertJsonPath('drill.label', 'Jobs');

    $this->getJson(panelUrl('jobs-overview', ['_page' => 'jobs']))
        ->assertOk()
        ->assertJsonPath('drill', null);
});

it('applies the service and environment scope to metric queries', function (): void {
    $this->getJson(panelUrl('requests-activity', ['service' => 'checkout', 'env' => 'prod']))->assertOk();

    Http::assertSent(function ($request): bool {
        $query = requestQuery($request)['query'] ?? null;

        return is_string($query)
            && str_contains($query, 'service_name="checkout"')
            && str_contains($query, 'deployment_environment_name="prod"');
    });
});

it('applies the scope to traceql searches', function (): void {
    $this->getJson(panelUrl('traffic-by-facet', ['service' => 'checkout', 'env' => 'prod']))->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        $q = requestQuery($request)['q'] ?? '';

        return str_contains($q, 'resource.service.name = "checkout"')
            && str_contains($q, 'resource.deployment.environment.name = "prod"');
    });
});

it('serves the spa shell for any dashboard path, with the boot json', function (string $path): void {
    $html = $this->get($path)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('<div id="app">', false)
        ->getContent();

    preg_match('~<script type="application/json" id="telemetry-ui-boot">(.*?)</script>~s', (string) $html, $m);
    $boot = json_decode($m[1] ?? '', true);

    expect($boot)->toBeArray()
        ->and($boot['base'])->toBe('/telemetry-ui')
        ->and($boot['api'])->toBe('/telemetry-ui/api/v2')
        ->and($boot['assets'])->toBe('/telemetry-ui/build')
        ->and($boot['csrf'])->toBeString();
})->with([
    '/telemetry-ui',
    '/telemetry-ui/p/requests',
    '/telemetry-ui/explore/requests',
    '/telemetry-ui/traces/abc123abc123abc123abc123abc123ab',
    '/telemetry-ui/anything/at/all',
]);
