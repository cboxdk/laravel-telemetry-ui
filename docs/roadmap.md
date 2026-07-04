---
title: Roadmap
description: Phased plan from connector foundation to actions and AI
weight: 99
---

# Roadmap

## Phase 1 — foundation (done)

- Connector layer: `MetricsSource`/`TracesSource`/`LogsSource`, drivers for
  Prometheus, Mimir (tenanted), Tempo and Loki, lazy `ConnectionManager`
  with `extend()`.
- Schema autodetection: pages with `detectMetric` patterns; built-in
  Statamic page lights up when statamic-telemetry metrics exist.
- Card/page registry, Livewire base `Card`, gate + routes, ECharts bundle,
  period selector.
- Tooling parity with `cboxdk/laravel-telemetry`: Pest 4, PHPStan level 8,
  Pint, arch tests.

## Phase 2 + 3 — full information architecture (done)

- Dashboard: requests (status-class stacked bars), duration (avg/p95),
  exceptions, jobs.
- Service/environment switcher in the sidebar, scoping PromQL, TraceQL and
  LogQL on every card.
- Requests (route table → trace drill-down), Jobs (outcomes, queue lag,
  per-job table), Commands, Scheduled Tasks, Exceptions (chart + by-class
  table + error-trace link), Queries (slowest query spans via TraceQL
  spanSets), Cache (ops + hit ratio), Outgoing (per-host table), Mail &
  Notifications, Users (sampled from `enduser.*` traces), Logs (Loki viewer
  with search + trace-id links), System (memory/CPU/filesystem/network).
- Traces: TraceQL search (raw query or quick filters, deep-linked from all
  drill-downs) + server-rendered waterfall with span attribute expansion.
- Dev environment: ../laravel-telemetry-demo (LGTM stack in one container
  with query APIs exposed).

## Later polish

- Per-service detection scoping (today detection is fleet-wide).
- Exceptions grouping with first/last-seen (needs app-side state).

Done: short-TTL query cache (`cache.ttl`, cached GET responses so a busy
dashboard doesn't hammer the backends), transient-blip retries, route
throttling, auto-refresh polling.

## Phase 4 — integrations (done)

- Issue trackers as a fourth signal (`IssuesSource`): GitHub (issues + PRs,
  verified live), Sentry (issue groups) and Linear (GraphQL), config-gated
  Issues page. Add your own via `ConnectionManager::extend()`.
- Actions: **create a ticket from an exception** — a `CreatesIssues` capability
  (GitHub, Linear) with a compose form in the drawer prefilled from the
  exception analysis; the drawer lands on the created ticket.

## Phase 5 — correlation, drill-down & agents (done)

The things a generic, app-only or read-only dashboard can't do:

- **Signal correlation** — opening a trace surfaces the host/runtime signals
  recorded around it (CPU, load, memory, network, RSS), each flagged against
  its typical baseline. Config-driven and fail-open per signal; headless
  `Analysis\SignalContext` is the reusable core. See
  [correlation](core-concepts/correlation.md).
- **Purpose-built drill-down** — routes/jobs/exceptions/hosts open dedicated
  detail pages scoped to one entity (throughput → latency → status codes → its
  traces), not a filtered trace search. Built on hidden pages + a
  `scopeMatchers()` card hook + `ScopesTo*` traits, so overview cards are reused
  scoped. See [custom detail pages](extension-points/detail-pages.md).
- **Dimensional filtering** — every trace attribute is a click-to-filter link;
  a Hosts page lists every reporting server.
- **Annotations, written** — `telemetry-ui:annotate` emits deploy/incident/…
  markers through the telemetry pipeline; `telemetry-ui:scan-versions` proactively
  annotates un-announced deploys. See [annotations](cookbook/annotations.md).
- **MCP server** — metrics/traces/logs + the correlation tools over the Model
  Context Protocol (stdio, or HTTP with OAuth 2.1 + DCR via `laravel/passport`),
  so an agent can drive incident RCA. See [MCP](cookbook/mcp.md).

## Production hardening (done)

- Backend failures log full detail server-side but show a generic message in
  the UI (no endpoint/body leak); MCP web fails loud on a half-configured OAuth
  setup; MCP tools are bounded (row/series/window caps + throttle); correlation
  baselines are cached; `tagValues` lookups are time+limit bounded.
- Feature-tested drill-down/detail pages, dimensional filtering, and the
  info-leak boundary.

## Next

- AI triage/chat on a trace, exception or incident (streamed via `wire:stream`),
  building on the MCP tools and `SignalContext`.
- Post-to-Slack action alongside ticket creation.
- Threshold / alert hints.
- Per-service detection scoping (today detection is fleet-wide).
