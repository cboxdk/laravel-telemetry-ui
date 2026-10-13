<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Servers;

use Cbox\TelemetryUi\Mcp\Tools\CompareTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\ListMonitorsTool;
use Cbox\TelemetryUi\Mcp\Tools\ListServicesTool;
use Cbox\TelemetryUi\Mcp\Tools\ListTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\MonitorTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryLogsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryMetricsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryRangeTool;
use Cbox\TelemetryUi\Mcp\Tools\SearchTracesTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceContextTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceMarkdownTool;
use Cbox\TelemetryUi\Mcp\Tools\TraceSummaryTool;
use Cbox\TelemetryUi\TelemetryUiManager;
use Composer\InstalledVersions;
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

    protected string $version = '2.0.0';

    protected string $instructions = <<<'MARKDOWN'
        You are connected to a Laravel app's observability stack (Tempo traces,
        Loki logs, Prometheus/Mimir metrics) via MCP. Use these tools to answer
        "what happened?" during an incident:

        - list_services — what's reporting, and which environments
        - query_metrics / query_range — PromQL
        - search_traces — TraceQL (e.g. { status = error })
        - query_logs — LogQL
        - list_traces — recent traces, by label (X-Telemetry-Label header) or
          only those with flag hints (errors, N+1, duplicates, slow queries)
        - trace_summary — one trace diagnosed: time per category, totals and
          flags (errors first, then N+1 shapes with the app file:line that ran
          them, identical repeats, slow queries, telemetry overhead). Start
          here when you have a trace id and want to know what is wrong.
        - trace_markdown — the same trace as one ready-to-paste Markdown
          snapshot (flags, timing, exceptions with app frames, what led up
          to the failure, query groups with SQL and origin), capped at 12 KB:
          for a PR description, an issue or a hand-off note.
        - compare_traces — two traces (ids, or labels) side by side: did the
          fix land? Totals, time per category, query shapes and flags diffed.
        - trace_context — a trace PLUS the host/runtime signals around it,
          flagged when they were out of their normal range: what was
          different about the box it ran on.
        - list_monitors — active monitors: rules that keep every trace of one
          route, path, user, job or tenant for a while (optionally with a CPU
          profile). Their traces carry span.telemetry.directive = <id>.
        - monitor — place or remove one, when the operator has enabled MCP
          write tools (TELEMETRY_UI_MCP_WRITE). Say why in `reason`.

        Responses omit empty keys; lists are paged (pass next_offset back as
        offset), and verbose=true adds full SQL and span ids.

        Everything is read-only except `monitor`, which is only offered when
        write tools are switched on.
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
        ListTracesTool::class,
        TraceSummaryTool::class,
        TraceMarkdownTool::class,
        CompareTracesTool::class,
        ListMonitorsTool::class,
    ];

    /**
     * Append app/package-contributed tools (registered via
     * TelemetryUi::mcpTool()) to the built-in read tools. Runs before the
     * server builds its context, so late registrations are still picked up.
     */
    protected function boot(): void
    {
        // Advertise the installed package version, not a constant that drifts.
        if (InstalledVersions::isInstalled('cboxdk/laravel-telemetry-ui')) {
            $this->version = InstalledVersions::getPrettyVersion('cboxdk/laravel-telemetry-ui') ?? $this->version;
        }

        // Write tools only when the operator asked for them.
        if ((bool) config('telemetry-ui.mcp.write', false)) {
            $this->tools[] = MonitorTool::class;
        }

        $extra = app(TelemetryUiManager::class)->mcpTools();

        if ($extra !== []) {
            $this->tools = [...$this->tools, ...$extra];
        }
    }
}
