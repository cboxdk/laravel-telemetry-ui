---
title: MCP server (query your telemetry from an agent)
description: Expose metrics, traces, logs and the analysis tools over the Model Context Protocol
weight: 34
---

# MCP server

`php artisan mcp:start telemetry-ui` serves the read side of your stack over the
[Model Context Protocol](https://modelcontextprotocol.io), built on the
first-party [`laravel/mcp`](https://github.com/laravel/mcp) package, so an
agent — Claude Desktop, Cursor, your own — can query metrics, traces, logs and
the correlation/analysis layer directly. It's the same data the dashboard
renders, made available for incident RCA in a chat:

> "Why did checkout error at 14:05? Look at the traces and the host metrics."

Everything goes through the same **read-only** drivers the dashboard uses.

## Wiring it up

Point your MCP client at the artisan command. For Claude Desktop / Cursor:

```json
{
  "mcpServers": {
    "telemetry-ui": {
      "command": "php",
      "args": ["artisan", "mcp:start", "telemetry-ui"],
      "cwd": "/path/to/your/app"
    }
  }
}
```

## Tools

| Tool | What it does |
| --- | --- |
| `list_services` | Services and environments reporting telemetry |
| `query_metrics` | Instant PromQL → samples |
| `query_range` | Range PromQL → per-series last/min/max |
| `search_traces` | TraceQL → matching trace summaries |
| `query_logs` | LogQL → matching log lines |
| `trace_context` | A trace **plus** the host/runtime signals around it and how they compare to normal — the "what happened / what was different" tool |
| `list_traces` | Recent traces, by label and/or only those with flag hints (error, 5xx, N+1, duplicate, slow query, overhead) |
| `trace_summary` | One trace diagnosed: identity, time per category, totals and flags — errors first, then N+1s with the app line that ran them |
| `compare_traces` | Two traces (ids or labels) side by side: duration, totals, time per category, query shapes and flags diffed |

`trace_context` is the one that makes RCA conversational: hand it a trace id
and the agent gets the request *and* the state of the box it ran on (CPU,
memory, load, …) at that moment, each flagged if it was out of its normal
range — the same correlation the trace drawer shows, as structured data.

## Diagnosing a trace

`trace_summary` reads one trace and says what is wrong with it, in the order
you would fix it:

```json
{
  "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
  "name": "GET /orders", "service": "shop", "kind": "server", "status": "ok",
  "http_status": 200, "duration_ms": 412.3, "label": "before",
  "timing_ms": { "db": 61.2, "view": 40.1, "other": 311 },
  "totals": { "queries": 54, "query_time_ms": 61.2, "n_plus_one_queries": 1, "duplicate_queries": 0, "telemetry_overhead_ms": 1.4, "query_shapes": 4 },
  "flag_counts": { "errors": 0, "warnings": 1 },
  "flags": [
    { "level": "warning", "section": "db", "message": "N+1: 50× select * from `users` where `users`.`id` = ? limit 1",
      "origin": "app/Http/Controllers/OrderController.php:42" }
  ],
  "flags_total": 1
}
```

- **Errors first**: exception records for the trace (with their file:line),
  a 5xx response, failed jobs, other failed spans. Then **warnings**: N+1
  query shapes (every run of the shape counted, with the app line that ran
  it), identical repeats (same SQL *and* bindings), slow query shapes
  (`traces.diagnostics.slow_query_ms`), and the telemetry's own overhead when
  it is a noticeable share of the trace.
- **Time per category** (db, cache, http, view, queue, other) never counts a
  span twice: a query run while rendering a view is the view's time; `other`
  is what is left of the root — your code, the framework, waiting.
- **Compact by default**: empty keys are left out, flags are paged (`limit`,
  default 25; pass `next_offset` back as `offset`). `verbose: true` adds the
  full SQL, span ids, per-shape timings and the function that ran it.

The N+1 and duplicate flags, the origins, the overhead, the root's
`db.query.slow.count` and labels need laravel-telemetry 3.2 or later. On older
traces the summary groups queries by their text and reports a statement that
ran `repeat_threshold` times as a *repeat*, without guessing which kind. If
the root counts slow queries that no span shows at
`traces.diagnostics.slow_query_ms`, a flag says so — keep that threshold at
the emitter's `traces.details.slow_span_ms`.

### Before and after a fix

Send the same request twice with an `X-Telemetry-Label` header —
laravel-telemetry 3.2 stamps it as `laravel.trace.label` on every span of the
request and of the jobs it dispatches — and compare by label:

```bash
curl -H 'X-Telemetry-Label: before' https://shop.test/orders
# fix the N+1, then
curl -H 'X-Telemetry-Label: after' https://shop.test/orders
```

> "Compare traces `before` and `after`."

`compare_traces` resolves each label to the newest trace whose root span carries it (within
`minutes`, default a day) and returns the duration and totals as
`{a, b, delta}`, time per category, how many query shapes appeared,
disappeared or ran a different number of times (with the top few), and the
flags present on only one side — matched by shape, so a fixed N+1 shows up
in `only_a` and a new one in `only_b`. Either side can be a trace id instead.

`list_traces` finds the traces to look at: `label` lists one label's runs,
`has_flags: true` keeps only traces with a hint of trouble. Hints come from
the root span's tallies (N+1, duplicate and, from 3.2, slow-query counts)
plus a bounded search for traces with an error span — and, for roots from
before 3.2, one for traces with a slow query; `trace_summary` is the full
diagnosis. Both tools ask the trace store for root spans (TraceQL
`nestedSetParent < 0`), with or without a label: the label is on every span,
the tallies only on the root.

## Remote (HTTP) with OAuth + Dynamic Client Registration

To let a hosted agent connect over the network — with proper auth and clients
that self-register — expose the server over HTTP. This uses `laravel/mcp`'s
built-in OAuth 2.1 authorization server and DCR endpoint on top of
`laravel/passport`; there's no custom OAuth code to write.

```bash
composer require laravel/passport   # then: php artisan passport:install
```

```dotenv
TELEMETRY_UI_MCP_WEB=true
# TELEMETRY_UI_MCP_PATH=telemetry-ui/mcp   # the HTTP endpoint
```

That registers the MCP POST endpoint (guarded by `auth:api`), the
`.well-known/oauth-*` discovery documents, and the **Dynamic Client
Registration** endpoint — so an MCP client can register itself and obtain a
token without any manual client setup. Passport stays optional: without
`TELEMETRY_UI_MCP_WEB` the package pulls nothing extra and the local stdio
server is all you get.

## Adding your own tool

The server exposes nine built-in read tools. A package can contribute its own —
say a tool that explains an autoscaler's decisions — by registering a
`Laravel\Mcp\Server\Tool` from a service provider:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::mcpTool(ExplainAutoscaleTool::class);
}
```

`TelemetryServer` merges registered tools with its built-ins when it boots, so
they appear on both the stdio and HTTP transports. Keep them read-only and
catch `SourceException` (returning `Response::error(...)`) exactly like the
built-in tools do, so a backend hiccup surfaces cleanly instead of as a 500.

## Security

The local command is stdio-only and read-only; there is no network listener,
and anyone who can run `php artisan` in your app can already read the same
backends. The HTTP transport is off by default and, when on, sits behind
Passport-issued tokens (`auth:api`) — plus the same read-only drivers, so a
token grants querying, never writes.
