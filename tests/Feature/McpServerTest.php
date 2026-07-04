<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\McpServer;
use Illuminate\Support\Facades\Http;

function mcp(): McpServer
{
    return app(McpServer::class);
}

it('handshakes on initialize', function (): void {
    $response = mcp()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

    expect($response['id'])->toBe(1)
        ->and($response['result']['protocolVersion'])->toBeString()
        ->and($response['result']['serverInfo']['name'])->toBe('telemetry-ui')
        ->and($response['result']['capabilities'])->toHaveKey('tools');
});

it('lists its tools with input schemas', function (): void {
    $response = mcp()->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

    $names = array_column($response['result']['tools'], 'name');

    expect($names)->toContain('list_services', 'query_metrics', 'search_traces', 'query_logs', 'trace_context')
        ->and($response['result']['tools'][1])->toHaveKeys(['name', 'description', 'inputSchema']);
});

it('returns null for a notification (no id)', function (): void {
    expect(mcp()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull();
});

it('errors on an unknown method', function (): void {
    $response = mcp()->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'no/such']);

    expect($response['error']['code'])->toBe(-32601)
        ->and($response['error']['message'])->toContain('no/such');
});

it('calls the query_metrics tool through the real driver', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['service_name' => 'cbox-web'], 'value' => [1735689600, '42']],
            ]],
        ]),
    ]);

    $response = mcp()->handle([
        'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
        'params' => ['name' => 'query_metrics', 'arguments' => ['query' => 'up']],
    ]);

    $text = $response['result']['content'][0]['text'];

    expect($response['result']['isError'])->toBeFalse()
        ->and($text)->toContain('cbox-web')
        ->and($text)->toContain('42');
});

it('reports a backend failure as an MCP tool error, not a crash', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('boom', 502)]);

    $response = mcp()->handle([
        'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
        'params' => ['name' => 'query_metrics', 'arguments' => ['query' => 'up']],
    ]);

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])->toContain('502');
});
