<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\ListServicesTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryMetricsTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceContextTool;
use Illuminate\Support\Facades\Http;

it('runs the query_metrics tool through the real driver', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['service_name' => 'cbox-web'], 'value' => [1735689600, '42']],
            ]],
        ]),
    ]);

    TelemetryServer::tool(QueryMetricsTool::class, ['query' => 'up'])
        ->assertOk()
        ->assertSee('cbox-web')
        ->assertSee('42');
});

it('surfaces a backend failure as an MCP tool error', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('boom', 502)]);

    TelemetryServer::tool(QueryMetricsTool::class, ['query' => 'up'])
        ->assertHasErrors();
});

it('lists services and environments', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/service_name/values*' => Http::response([
            'status' => 'success', 'data' => ['cbox-web', 'billing'],
        ]),
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => ['production']]),
    ]);

    TelemetryServer::tool(ListServicesTool::class)
        ->assertOk()
        ->assertSee('cbox-web');
});

it('correlates host context for a trace via trace_context', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
    ]);

    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                    ['key' => 'host.name', 'value' => ['stringValue' => 'web-3']],
                ]],
                'scopeSpans' => [['spans' => [
                    ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
                ]]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '0.9']]],
            ]],
        ]),
    ]);

    TelemetryServer::tool(TraceContextTool::class, ['trace_id' => 'abc123abc123abc123abc123abc123ab'])
        ->assertOk()
        ->assertSee('Host CPU')
        ->assertSee('context');
});
