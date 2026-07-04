<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Servers;

use Cbox\TelemetryUi\Mcp\Tools\ListServicesTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryLogsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryMetricsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryRangeTool;
use Cbox\TelemetryUi\Mcp\Tools\SearchTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceContextTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * Exposes the read side of the stack — metrics, traces, logs and the
 * correlation/analysis layer — over MCP, so an agent (Claude Desktop, Cursor,
 * your own) can query it for incident RCA. The same data the dashboard renders,
 * through the same read-only drivers.
 */
class TelemetryServer extends Server
{
    protected string $name = 'Telemetry UI';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        You are connected to a Laravel app's observability stack (Tempo traces,
        Loki logs, Prometheus/Mimir metrics) via MCP. Use these tools to answer
        "what happened?" during an incident:

        - list_services — what's reporting, and which environments
        - query_metrics / query_range — PromQL
        - search_traces — TraceQL (e.g. { status = error })
        - query_logs — LogQL
        - trace_context — a trace PLUS the host/runtime signals around it,
          flagged when they were out of their normal range. Start here when you
          have a trace id and want to know what was different.

        Everything is read-only.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListServicesTool::class,
        QueryMetricsTool::class,
        QueryRangeTool::class,
        SearchTracesTool::class,
        QueryLogsTool::class,
        TraceContextTool::class,
    ];
}
