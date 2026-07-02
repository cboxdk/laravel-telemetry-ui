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
  Statamic page (static cache card) lights up when statamic-telemetry
  metrics exist.
- Card/page registry, Livewire base `Card`, gate + routes, ECharts bundle,
  period selector, first built-in card (Requests overview).
- Tooling parity with `cboxdk/laravel-telemetry`: Pest 4, PHPStan level 8,
  Pint, arch tests.

## Phase 2 — core screens

- Dashboard (activity, duration, exceptions, jobs — the Nightwatch landing).
- Requests: route table (status classes, avg, p95) → trace drill-down.
- Traces: TraceQL search + waterfall (request detail timeline).
- Service/environment switcher; short-TTL query cache.

## Phase 3 — full IA

- Jobs (incl. queue lag + dispatch origin), Exceptions/Issues grouping,
  Queries, Scheduled Tasks, Commands, Cache, Outgoing, Mail/Notifications,
  Users (`enduser.*`), Logs (Loki, trace-correlated), System.
- Full Statamic page: Stache warm/clear, Glide per preset, forms, content
  changes, gauges; trace filters on `statamic.site`/`statamic.collection`.
- Per-service detection scoping once the service switcher exists (today
  detection is fleet-wide).
- Dev environment: docker-compose with Tempo + Loki + Prometheus + a demo app.

## Phase 4 — actions & intelligence (out of scope for now)

- `IssuesSource` contract: Sentry read, Linear/GitHub ticket creation from
  exception groups.
- AI resolve/chat on traces and issues (streamed via `wire:stream`).
- Thresholds/alert hints.
