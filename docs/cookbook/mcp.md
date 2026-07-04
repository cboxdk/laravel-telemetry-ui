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

## Security

The command is stdio-only and read-only; there is no network listener. Anyone
who can run `php artisan` in your app can already read the same backends, so
the MCP surface adds no new access — just a new way to ask.

A remote (HTTP) transport with OAuth 2.1 + Dynamic Client Registration — so a
hosted agent can connect over the network with proper auth — is a planned next
layer on `laravel/mcp`'s `Mcp::web()`; today only the local stdio server ships.
