---
title: v2 implementation plan
description: Phased build plan for the v2 JSON API + React SPA — API shapes, routes, components.
weight: 4
---

# v2 implementation plan

Companion to [v2-architecture.md](v2-architecture.md). Branch `feat/v2-spa`, big-bang: Livewire is
removed in the same branch; the feature-parity checklist in the architecture doc is the gate.

## Phases

1. **Foundation (PHP).** `Http/Api/RequestScope` (period/range/service/env/where from query params,
   bounded by `ScopeLock`), `ScopesQueries` moved to `Support/Concerns`, framework-free `Panels/Panel`
   base + `Panels/Ui` builders (the JSON contract), `Dimensions` registry on the manager, typed API
   errors, SPA shell + hashed asset serving. Livewire, `Cards/`, `resources/views/`, old JS/CSS deleted.
2. **Panels.** Every v1 card becomes a `Panels\Builtin\*` class whose `data()` returns a typed array
   (`kind` = chart | stats | table | bars | composite | heatmap | graph | logs | header | kv | code |
   callout | hidden). Same queries/analysis, no Blade. Card tests are ported to hit the panel endpoint.
3. **Explore + entities (PHP).** `Explore\*` services: requests/traces (TraceQL through the IR),
   logs (LogQL), errors (Loki records + browser spans); facets (exact via `AggregatesSpans`, else a
   labelled read-side sample), group-by, time×latency heatmap. `EntityStory` builds the entity page.
4. **SPA.** React + Vite + TS, TanStack Query/Router/Virtual, ECharts (lazy chunk). Shell, scope,
   filter bar, facet panel, Explore, entity pages, panel pages, stacked drawer, ⌘K, live tail.
5. **Verify + close.** `composer check` green, Vitest green, live-verified against telemetryd via
   the demo, parity checklist filled in.

## API (`{path}/api/v2`, gate middleware, JSON, typed errors)

Every endpoint accepts the scope params: `period`, `from`, `to`, `service`, `env`, `where[]`
(`key<op>value`, op ∈ `= != =~ !~ > >= < <=`). Errors: `{"error":{"type":"backend|forbidden|invalid|not_found","message":"…"}}`
with 502/403/422/404.

| Method | Path | Returns |
|---|---|---|
| GET | `/bootstrap` | app config: base paths, brand, nav (pages by group, detection-filtered, gate-filtered), navLinks, connections, scope options + locks, dimensions, entity types, periods, abilities |
| GET | `/pages/{page}` | `{page, label, group, panels: [{id, span}]}` |
| GET | `/panels/{panel}` | panel data (`kind` + payload); extra params (`route`, `host`, …) scope entity panels |
| GET | `/explore/{signal}` | signal ∈ requests/traces/logs/errors: `{rows, stats, groups?, heatmap?, sample: {size, exact}}`; params `q`, `groupBy`, `limit` |
| GET | `/facets/{signal}` | `{facets: [{key, label, group, values: [{value, count}]}], exact, sample}`; params `keys[]` |
| GET | `/entities/{type}` | entity index: top values with RED (a group-by over the type's key) |
| GET | `/entities/{type}/story?value=` | the story: headline, RED, trend, top dimensions, slowest/failing traces, correlated errors, deploys, raw, panels |
| GET | `/traces/{id}` | trace + waterfall + chain + identities + SignalContext + TraceProfile + RequestReport + TraceLogs |
| GET | `/errors/{group}` | ErrorGroupReport (stats, occurrences, detail, request, suspect deploy, releases, draft) |
| GET | `/issues/{id}` · POST `/issues` | tracker issue · create ticket (`manageTelemetryUi`) |
| GET | `/annotations` | deploy/change markers in range |
| GET | `/stream/{signal}` | SSE live tail (logs, requests); `event: rows` batches, reconnect via `Last-Event-ID` |

Catch-all `GET {path}/{any?}` returns the SPA shell (`index.html` with base/api paths + CSRF in a
bootstrap `<script type="application/json">`). Hashed chunks under `{path}/build/*`.

## SPA routes

| Route | Screen |
|---|---|
| `/` | Overview (dashboard panels) |
| `/explore/$signal` | Explore: filter bar, facet panel, stats, heatmap, group-by, virtual list |
| `/entities/$type` | entity index (routes, queries, views, hosts, jobs, declared dimensions) |
| `/entity/$type?value=…` | entity story page, Raw tab last (values carry slashes, so they travel as a query param) |
| `/p/$page` | any registered page (Jobs, Queues, Cache, Statamic …) as a panel grid |
| `/errors/$group` | full issue page |
| `/traces/$traceId` | full trace page |
| `?drawer=trace:…~error:…~issue:…` | stacked, deep-linkable drawer over any route |

## Components

Shell (Rail, Subnav, TopBar: ScopePicker, PeriodPicker, ConnectionSwitcher, theme), FilterBar,
FacetPanel, GroupBy, StatsRow, Heatmap, VirtualList, Chart (ECharts, lazy), PanelGrid + renderers per
`kind`, DimensionChip (filter / exclude / group / open / link out), EntityStory, RawAttributes,
DrawerStack (TraceView waterfall, ErrorGroupView, IssueView, ComposeTicket), CommandPalette,
LiveTail (EventSource + polling fallback), typed error/empty states.
