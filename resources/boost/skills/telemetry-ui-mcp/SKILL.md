---
name: telemetry-ui-mcp
description: "Investigate production behaviour through the telemetry-ui MCP server (cboxdk/laravel-telemetry-ui): find traces, diagnose one with trace_summary, compare before/after a fix with compare_traces, write a trace up with trace_markdown, query PromQL/TraceQL/LogQL, and place or list monitors. Use when asked why a request was slow or failed, whether a fix landed, what happened during an incident, or to record more of one route, user, tenant or job."
license: MIT
metadata:
  author: cboxdk
---

# Investigating with the telemetry-ui MCP tools

## When to use this skill

Use it when the `telemetry-ui` MCP server is connected and the question is
about what the app actually did: a slow or failing request, an N+1, an
incident, or whether a fix changed anything. Read the telemetry before
guessing from the code.

The server is `php artisan mcp:start telemetry-ui` (stdio), or the HTTP
endpoint when `TELEMETRY_UI_MCP_WEB=true`. If the tools are missing, tell the
user how to wire it up (see `vendor/cboxdk/laravel-telemetry-ui/docs/cookbook/mcp.md`)
rather than working around it.

## The tools

| Tool | Use it to | Key arguments |
|---|---|---|
| `list_services` | see which services and environments report | none |
| `list_traces` | find traces to look at | `label`, `has_flags`, `service`, `minutes` (60), `limit` (20), `cursor` |
| `trace_summary` | diagnose one trace: time per category, totals, flags | `trace_id`, `verbose`, `limit` (25), `offset` |
| `trace_markdown` | the same trace as a Markdown snapshot (12 KB max) for a PR, issue or hand-off | `trace_id` |
| `compare_traces` | before vs after: totals, time per category, query shapes and flags diffed | `a`, `b` (trace id or label), `minutes` (1440), `verbose` |
| `trace_context` | a trace plus host/runtime signals around it, flagged when abnormal | `trace_id` |
| `search_traces` | raw TraceQL | `query`, `minutes`, `limit` |
| `query_metrics` / `query_range` | PromQL, instant or over a window | `query` (+ `minutes`) |
| `query_logs` | LogQL | `query`, `minutes`, `limit` |
| `list_monitors` | active monitors per service | `service` |
| `monitor` | place or stop a monitor (write tool, see below) | `service`, `match`, `value`, `key`, `minutes`, `profile`, `reason`, `remove` |

Responses leave out empty keys. Lists page: pass `next_offset` back as
`offset` (`trace_summary`) or `next_cursor` back as `cursor` (`list_traces`).

## Workflow: "why is this slow?"

1. No trace id yet? `list_traces` with `has_flags: true` (and `service`) lists
   traces with a hint of trouble: errors, 5xx, N+1, duplicates, slow queries.
2. `trace_summary` on the trace. Flags come errors first, then warnings. An
   N+1 flag names the repeated SQL and its `origin` (`app/...php:42`): open
   that file and line, it is the loop or Blade view to fix with eager loading.
   A duplicate flag (same SQL, same values) wants memoizing, not eager loading.
   An `http` N+1 flag (laravel-telemetry 3.6+) is one API endpoint called once
   per id: use the API's batch endpoint or `Http::pool()`.
3. Need the SQL, span ids or per-shape timings? Repeat with `verbose: true`.
4. Was the box itself unhealthy? `trace_context` shows CPU, memory and load
   around the trace and flags what was out of its normal range.

## Workflow: "did the fix land?"

Label the two runs with the `X-Telemetry-Label` header (laravel-telemetry
3.5+ puts it on every span as `laravel.trace.label`):

```bash
curl -H 'X-Telemetry-Label: before' https://app.test/orders
# apply the fix
curl -H 'X-Telemetry-Label: after' https://app.test/orders
```

Then `compare_traces` with `a: "before"`, `b: "after"`. A fixed N+1 shows up
in the flags present only on `a`; a new one only on `b`. Report the deltas
(duration, query count) rather than "looks better".

## Workflow: write it up

`trace_markdown` returns a finished snapshot (flags, timing, exceptions with
app frames, query groups with SQL and origin). Paste it as-is into the PR
description or issue; do not paraphrase it.

## Monitors: record more of one slice

A monitor keeps every trace (with detail spans, optionally a CPU profile)
for one route, path, user, context value (e.g. `tenant.id`) or job, for
1 to 1440 minutes, then expires. Kept traces carry `telemetry.directive = <id>`;
query them with `search_traces` and `{ span.telemetry.directive = "<id>" }`.

- `list_monitors` is always available.
- `monitor` exists only when the operator set `TELEMETRY_UI_MCP_WRITE=true`.
  If it is missing, say so and suggest `php artisan telemetry:watch`
  (laravel-telemetry) instead of looking for a workaround. Over HTTP it also
  needs the `manageTelemetryUi` ability.
- Keep it narrow and short, always give a `reason`, and stop it with
  `remove: <id>` when you are done.

## Limits to respect

- Every tool runs inside the viewer's scope lock (`telemetry-ui.scope.lock`
  config or `TelemetryUi::restrictScopeUsing()`). A trace from a service
  outside the lock answers "not found"; that is the lock, not a missing
  trace. Do not try to query around it.
- `trace_markdown` includes stack traces and source only when the viewer may
  open the exceptions page.
- The tools only read, except `monitor`. Never claim to have changed
  sampling or config through them.
