<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\TraceMarkdownTool;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * "Copy as Markdown" over the API (`traces/{id}/markdown`,
 * `errors/{group}/markdown`) and MCP (`trace_markdown`): the same gate, scope
 * lock and scope as the views they snapshot.
 */
const SNAP_TRACE_ID = 'abc123abc123abc123abc123abc123ab';

function snapAttr(string $key, string|int $value): array
{
    return ['key' => $key, 'value' => is_int($value) ? ['intValue' => (string) $value] : ['stringValue' => $value]];
}

/** A failing request from service $service, and the exception record that names it. */
function fakeSnapshotBackends(string $service = 'shop'): void
{
    $t0 = 1_735_689_600_000_000_000;

    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => [[
            'resource' => ['attributes' => [snapAttr('service.name', $service)]],
            'scopeSpans' => [['spans' => [
                [
                    'spanId' => 'root', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER',
                    'startTimeUnixNano' => (string) $t0, 'endTimeUnixNano' => (string) ($t0 + 200_000_000),
                    'status' => ['code' => 'STATUS_CODE_ERROR'],
                    'attributes' => [snapAttr('http.response.status_code', 500)],
                ],
                [
                    'spanId' => 'q1', 'parentSpanId' => 'root', 'name' => 'db.query', 'kind' => 'SPAN_KIND_CLIENT',
                    'startTimeUnixNano' => (string) ($t0 + 5_000_000), 'endTimeUnixNano' => (string) ($t0 + 9_000_000),
                    'attributes' => [snapAttr('db.system.name', 'mysql'), snapAttr('db.query.text', 'select * from `orders` where `id` = ?')],
                ],
            ]]],
        ]]]),
        'loki.test:3100/*' => Http::response(lokiStreams([[
            'stream' => [
                'service_name' => $service,
                'trace_id' => SNAP_TRACE_ID,
                'exception_group' => 'abc123def456',
                'exception_type' => 'App\\Exceptions\\PaymentDeclined',
                'exception_message' => 'Card declined by issuer',
                'exception_file' => 'app/Services/Checkout.php',
                'exception_line' => '42',
                'exception_stacktrace' => "#0 app/Services/Checkout.php(42): charge()\n#1 {main}",
                'exception_source' => "  41| \$card = \$request->card();\n> 42| throw new PaymentDeclined();",
            ],
            'values' => [[(string) ($t0 + 100_000_000), 'exception']],
        ]])),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);
}

function allowPages(string ...$pages): void
{
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => $page === null || in_array($page, $pages, true));
}

it('serves a trace as Markdown in JSON', function (): void {
    allowPages('traces', 'exceptions');
    fakeSnapshotBackends();

    $response = $this->getJson(apiUrl('traces/'.SNAP_TRACE_ID.'/markdown'))
        ->assertOk()
        ->assertJsonPath('traceId', SNAP_TRACE_ID)
        ->assertJsonPath('truncated', false)
        ->assertJsonPath('omitted', []);

    $markdown = (string) $response->json('markdown');

    expect($markdown)
        ->toStartWith('# Trace: GET /orders — failed, HTTP 500, 200 ms')
        ->toContain('`App\\Exceptions\\PaymentDeclined`: Card declined by issuer')
        ->toContain('> 42| throw new PaymentDeclined();')
        ->toContain('#0 app/Services/Checkout.php(42): charge()')
        ->and($response->json('bytes'))->toBe(strlen($markdown));
});

it('serves the Markdown itself with format=text', function (): void {
    allowPages('traces', 'exceptions');
    fakeSnapshotBackends();

    $response = $this->get(apiUrl('traces/'.SNAP_TRACE_ID.'/markdown', ['format' => 'text']))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/markdown; charset=UTF-8')
        ->and($response->getContent())->toStartWith('# Trace: GET /orders');
});

it('keeps the stacktrace and source out for a viewer who may not open the exceptions page', function (): void {
    allowPages('traces');
    fakeSnapshotBackends();

    $markdown = (string) $this->getJson(apiUrl('traces/'.SNAP_TRACE_ID.'/markdown'))->assertOk()->json('markdown');

    expect($markdown)
        ->toContain('`App\\Exceptions\\PaymentDeclined`: Card declined by issuer')
        ->not->toContain('throw new PaymentDeclined();')
        ->not->toContain('charge()');
});

it('treats a trace outside the scope lock as absent', function (): void {
    allowPages('traces', 'exceptions');
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    fakeSnapshotBackends('billing');

    $this->getJson(apiUrl('traces/'.SNAP_TRACE_ID.'/markdown'))->assertNotFound();
});

it('forbids a trace snapshot to a viewer who may not read traces', function (): void {
    allowPages('exceptions');
    fakeSnapshotBackends();

    $this->getJson(apiUrl('traces/'.SNAP_TRACE_ID.'/markdown'))->assertForbidden();
});

it('serves an error group as Markdown, under the exceptions gate', function (): void {
    allowPages('exceptions');
    fakeSnapshotBackends();

    $markdown = (string) $this->getJson(apiUrl('errors/abc123def456/markdown'))
        ->assertOk()
        ->assertJsonPath('group', 'abc123def456')
        ->json('markdown');

    expect($markdown)
        ->toStartWith('# PaymentDeclined: Card declined by issuer')
        ->toContain('> 42| throw new PaymentDeclined();')
        ->toContain('/telemetry-ui/api/v2/traces/'.SNAP_TRACE_ID.'/markdown?format=text');

    // The issue view's own copy is the same snapshot.
    expect($this->getJson(apiUrl('errors/abc123def456'))->assertOk()->json('llm'))->toBe($markdown);

    allowPages('traces');
    $this->getJson(apiUrl('errors/abc123def456/markdown'))->assertForbidden();
});

it('gives an agent the trace snapshot over MCP', function (): void {
    fakeSnapshotBackends();

    TelemetryServer::tool(TraceMarkdownTool::class, ['trace_id' => SNAP_TRACE_ID])
        ->assertOk()
        ->assertSee('# Trace: GET /orders')
        ->assertSee('## Exceptions')
        ->assertSee('## What led up to it');
});

it('says so over MCP when a trace has no spans', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response(['batches' => []]), 'loki.test:3100/*' => Http::response(lokiStreams([]))]);

    TelemetryServer::tool(TraceMarkdownTool::class, ['trace_id' => SNAP_TRACE_ID])->assertHasErrors();
});
