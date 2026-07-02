---
title: Design direction
description: Nightwatch-inspired information architecture, mapped to our own telemetry schema
weight: 1
---

# Design direction

Laravel Nightwatch is the UX benchmark: dark, dense, monospace-accented,
sidebar of Laravel-shaped sections, a global period selector (1H/24H/7D/14D/30D),
sortable tables with sparkline context, and a per-request timeline view.
We adopt that information architecture — and improve on it where owning the
full Grafana stack lets us.

## Where we beat Nightwatch

1. **No agent, no cloud, no sampling you don't control.** Data comes from the
   Tempo/Loki/Mimir you already run; retention and cost are your policy.
2. **Real distributed traces.** Nightwatch shows one app's request timeline;
   Tempo traces cross service boundaries (`Http::withTraceparent()`, queue
   propagation), so the waterfall spans checkout → billing → worker.
3. **Extensible in-process.** Every screen is a Livewire card; your packages
   (queue autoscale, custom spans, domain metrics) add pages without JS.
4. **Multi-service by default.** `service_name` is a label on everything, so
   one dashboard installation covers the whole fleet with a service switcher,
   not one dashboard per app.
5. **Actions, not just charts** (roadmap): create Linear/GitHub tickets from
   an exception group, AI-assisted "explain this trace".

## Information architecture

Sidebar (page slugs in parentheses; groups render as section headers):

- Dashboard (`dashboard`)
- Issues (`issues`) — exception groups with count/users/first/last seen
- **Activity**: Requests (`requests`), Jobs (`jobs`), Commands (`commands`),
  Scheduled Tasks (`schedule`), Exceptions (`exceptions`), Queries
  (`queries`), Notifications (`notifications`), Mail (`mail`), Cache
  (`cache`), Outgoing Requests (`outgoing`)
- **Monitoring**: Users (`users`), Logs (`logs`), System (`system`)
- Traces (`traces`) — TraceQL search + waterfall
- **Autodetected**: Statamic (`statamic`) — appears only when `statamic_*`
  metrics exist (cboxdk/statamic-telemetry: static cache, Stache, Glide,
  forms, content changes; spans filterable on `statamic.site`,
  `statamic.collection`, …). Other emitter packages can register detected
  pages the same way.

Global chrome: service/environment switcher (top of sidebar, driven by
`label_values(service_name)` + `deployment_environment_name`), period
selector (top right), and on every page a route from aggregate → trace:
tables link to filtered TraceQL searches, trace rows open the waterfall.

## Screen → query mapping

All names below are the stable schema emitted by `cboxdk/laravel-telemetry`.

| Screen | Primary data |
| --- | --- |
| Dashboard | req/min + error rate from `http_server_request_duration_milliseconds_count` (status label); avg/p95 via `histogram_quantile`; exceptions from `exceptions_reported_total`; jobs from `queue_jobs_{processed,failed,released}_total` |
| Requests | table per `http_route`×`http_request_method`: count by status class, avg, p95; drill-down: TraceQL `{ .http.route = "X" }` sorted by duration |
| Request detail | Tempo trace → waterfall of spans (`db.query.text`, cache ops, mail, outgoing HTTP), resource attrs (`deployment.id`, `php.memory.peak_bytes`), `enduser.id` |
| Jobs | `queue_job_duration_milliseconds`, `queue_job_wait_time_milliseconds` (queue lag!), outcome counters; traces via `.laravel.job.class`, dispatch origin via `.messaging.origin.name` |
| Commands | `command_duration_milliseconds`, `commands_{completed,failed}_total` |
| Scheduled Tasks | `schedule_task_duration_milliseconds`, `schedule_tasks_{processed,failed,skipped}_total` |
| Exceptions / Issues | `exceptions_reported_total` by `exception` label; trace samples via TraceQL `{ status = error }`; correlated logs via Loki `level=error` |
| Queries | `db_query_time_ms`/`db_query_count` span tallies; slow queries via TraceQL `{ span.db.query.text != "" && duration > N }` |
| Cache | `cache_operations_total` by operation/store (hit ratio) |
| Outgoing | `http_client_request_duration_milliseconds` by `server_address`, `http_client_connection_failures_total` |
| Mail / Notifications | `mail_sent_total`, `notifications_sent_total` by channel |
| Users | TraceQL aggregations on `.enduser.id` (most active, most errors); per-guard via `.enduser.guard` |
| Logs | Loki `{service_name="X"}` streams from the `telemetry` log channel, trace-id links back to Tempo |
| System | `system_memory_*`, `system_cpu_*`, `system_filesystem_*`, `worker_memory_*` |

## Visual language

Hand-written CSS (`public/telemetry-ui.css`), no Tailwind build: near-black
surfaces (`#09090b`/`#101012`), 1px `#232326` borders, 10px radius cards,
uppercase 12px card titles, monospace for numbers/labels/timestamps, ECharts
with a muted palette (green primary, amber 4xx, red 5xx). Density over
whitespace; empty and error states are quiet monospace lines, not modals.
