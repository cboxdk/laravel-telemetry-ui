---
title: MCP server (query your telemetry from an agent)
description: Expose metrics, traces, logs and the analysis tools over the Model Context Protocol
weight: 3
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

`trace_context` is the one that makes RCA conversational: hand it a trace id
and the agent gets the request *and* the state of the box it ran on (CPU,
memory, load, …) at that moment, each flagged if it was out of its normal
range — the same correlation the trace drawer shows, as structured data.

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

The server exposes six built-in read tools. A package can contribute its own —
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
