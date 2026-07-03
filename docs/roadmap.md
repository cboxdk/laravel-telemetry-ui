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
- Short-TTL query cache for busy dashboards; auto-refresh polling.
- Exceptions grouping with first/last-seen (needs app-side state).

## Phase 4 — integrations (in progress)

- Issue trackers as a fourth signal (`IssuesSource`): GitHub (issues + PRs,
  verified live), Sentry (issue groups) and Linear (GraphQL), config-gated
  Issues page. Add your own via `ConnectionManager::extend()`.
- Actions: **create a ticket from an exception** — a `CreatesIssues` capability
  (GitHub, Linear) with a compose form in the drawer prefilled from the
  exception analysis; the drawer lands on the created ticket.
- Next: post to Slack, AI-assisted triage on the analysis.

## Phase 5 — actions & intelligence (out of scope for now)

- `IssuesSource` contract: Sentry read, Linear/GitHub ticket creation from
  exception groups.
- AI resolve/chat on traces and issues (streamed via `wire:stream`).
- Thresholds/alert hints.
