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
2. **The full infra chain.** Nightwatch shows one app's request timeline; here
   the trace carries the whole path — edge proxy (Traefik/nginx/HAProxy),
   eBPF-instrumented infra (Grafana Beyla) and the app — because
   laravel-telemetry continues the incoming `traceparent`. The waterfall
   renders a "request chain" of the server spans and colours/badges each
   service by kind (proxy / beyla / app). The **Service graph** card (Tempo
   metrics-generator) shows who-calls-whom with rate/errors/p95.
3. **Extensible in-process.** Every screen is a Livewire card; your packages
   (queue autoscale, custom spans, domain metrics) add pages without JS.
4. **Multi-service by default.** `service_name` is a label on everything, so
   one dashboard installation covers the whole fleet with a service +
   environment switcher, not one dashboard per app.
5. **Slice by anything.** The Users page facets traffic by user, guard, type
   or client IP — or any custom span attribute (`team.id`, `statamic.site`,
   …) your app adds. Sampled from traces, so unbounded dimensions stay out of
   metric labels.
6. **Actions, not just charts** (roadmap): create Linear/GitHub tickets from
   an exception group, AI-assisted "explain this trace".

## Navigation & detail

- **Stacked slide-in drawers** — clicking any trace or issue link slides its
  detail in from the right without leaving the list. Dig deeper — open a trace
  from inside an issue, or a referenced trace from a trace — and it *stacks*: a
  back button and breadcrumb trail return you to where you came from with the
  context intact. The top of the stack mirrors to `?trace=`/`?issue=` so the
  current view is shareable and browser-back closes it. cmd/ctrl-click still
  opens the full page in a new tab.
- **Command palette** — ⌘K / Ctrl+K (or `/`) fuzzy-jumps to any page, service
  or environment; paste a 32-hex trace id to open its waterfall.
- **Copy link** — shares the exact current view (filters, range, scope) with
  anyone who already has access. (Public no-auth links are intentionally not
  built — they'd need signed, expiring, scoped tokens.)
- **Sparklines** — per-row trend mini-charts in the routes, jobs and outgoing
  tables, colored by row health.

## Time controls

A global period selector (15M–30D), a **custom absolute range** picker, and
**drag-to-zoom** on any chart (ECharts toolbox → sets `?from`/`?to` for the
whole dashboard). An **auto-refresh** control (off / 10 / 30 / 60s) re-renders
every card in place via a broadcast Livewire event, without a full page load.

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
- **Autodetected group — Statamic** (cboxdk/statamic-telemetry): a sidebar
  group whose subpages each detect their own metric family, so a site sees
  only the sections it emits — Static Cache (`statamic_static_cache.*`),
  Stache (`statamic_stache.*`), Glide (`statamic_glide.*`), Forms
  (`statamic_forms.*`), Content (`statamic_content_changes.*`) and Inventory
  (`statamic_(entries|assets|users)_count`, the opt-in gauges). Other emitter
  packages can register detected pages/groups the same way.

## Annotations

Point-in-time markers are drawn as vertical lines across **every chart**, the
way Grafana annotations map regressions to deploys. `php artisan
telemetry:deploy` emits an `app.deployment` event into the logs backend; the
UI reads it back (id + notes from the event's structured metadata) and the
dashboard's Deploys card lists them with trace links. Markers are configurable
in `telemetry-ui.annotations.markers` (event name → label/color), scope-aware,
and cached so a page of charts costs one Loki query.

## Infra chain expectations

To see proxies and Beyla in the waterfall the trace must be *one* trace:
- **Reverse proxies** (Traefik, nginx via OpenTelemetry module, HAProxy,
  Envoy) inject/propagate W3C `traceparent`; laravel-telemetry continues it
  (`traces.continue_incoming`, on by default), so proxy server-spans become
  the ancestors of the Laravel root span.
- **Grafana Beyla** (eBPF) is classified via `telemetry.sdk.name`/
  `telemetry.distro.name` on the resource and badged `beyla`.
- **Service graph** needs Tempo's metrics-generator `service-graphs`
  processor remote-writing to your metrics backend.
Service kind (proxy / beyla / app) is inferred from resource attributes first,
then the service name (`traefik`, `nginx`, `haproxy`, `envoy`, `caddy`, …).

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
| Users | TraceQL facets on `.enduser.id`/`.enduser.guard`/`.enduser.type`/`.client.address` or any custom attribute; traces + sampled error counts per value |
| Traces | TraceQL search + waterfall with request-chain header (proxy→app), collapsible span subtrees, per-service colours; Service graph from `traces_service_graph_*` |
| Logs | Loki `{service_name="X"}` streams from the `telemetry` log channel, trace-id links back to Tempo |
| System | `system_memory_*`, `system_cpu_*`, `system_filesystem_*`, `worker_memory_*` |

## Visual language

Hand-written CSS (`public/telemetry-ui.css`), no Tailwind build: near-black
surfaces (`#09090b`/`#101012`), 1px `#232326` borders, 10px radius cards,
uppercase 12px card titles, monospace for numbers/labels/timestamps, ECharts
with a muted palette (green primary, amber 4xx, red 5xx). Density over
whitespace; empty and error states are quiet monospace lines, not modals.
