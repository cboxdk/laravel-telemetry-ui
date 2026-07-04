<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\Period;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

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
                            ['key' => 'enduser.id', 'value' => ['intValue' => '7']],
                            ['key' => 'enduser.guard', 'value' => ['stringValue' => 'web']],
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

it('renders every built-in page', function (string $page): void {
    $path = $page === 'dashboard' ? '/telemetry-ui' : '/telemetry-ui/'.$page;

    $this->get($path)->assertOk();
})->with([
    'dashboard', 'traces', 'requests', 'jobs', 'commands', 'schedule',
    'exceptions', 'queries', 'cache', 'outgoing', 'mail',
    'statamic-cache', 'statamic-stache', 'statamic-glide', 'statamic-forms',
    'statamic-content', 'statamic-inventory',
    'analytics', 'frontend', 'users', 'logs', 'system',
]);

it('renders every built-in page in every period', function (string $period): void {
    $this->get('/telemetry-ui?period='.$period)->assertOk();
})->with(array_map(fn (Period $period): string => $period->value, Period::cases()));

it('applies the service and environment scope to metric queries', function (): void {
    $this->get('/telemetry-ui/requests?service=checkout&env=prod')->assertOk();

    Http::assertSent(function ($request): bool {
        $query = requestQuery($request)['query'] ?? null;

        return is_string($query)
            && str_contains($query, 'service_name="checkout"')
            && str_contains($query, 'deployment_environment_name="prod"');
    });
});

it('applies the scope to traceql searches', function (): void {
    $this->get('/telemetry-ui/users?service=checkout&env=prod')->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        $q = requestQuery($request)['q'] ?? '';

        return str_contains($q, 'resource.service.name = "checkout"')
            && str_contains($q, 'resource.deployment.environment.name = "prod"');
    });
});

it('renders the trace waterfall page', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1000000000', 'endTimeUnixNano' => '2000000000'],
                    ['spanId' => 'a2', 'parentSpanId' => 'a1', 'name' => 'db.query', 'kind' => 3, 'startTimeUnixNano' => '1200000000', 'endTimeUnixNano' => '1400000000'],
                ]]],
            ]],
        ]),
    ]);

    $this->get('/telemetry-ui/traces/abc123abc123abc123abc123abc123ab')
        ->assertOk()
        ->assertSee('GET /orders')
        ->assertSee('db.query');
});

it('shows a friendly error when the trace backend fails', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('boom', 502)]);

    $this->get('/telemetry-ui/traces/abc123abc123abc123abc123abc123ab')
        ->assertOk()
        ->assertSee('status 502');
});
