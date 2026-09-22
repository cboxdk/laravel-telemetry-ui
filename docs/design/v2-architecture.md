---
title: v2 architecture — decoupled JSON API + React SPA
description: Remove Livewire; the package becomes a versioned JSON API plus a built React single-page app served as static assets. The framework-agnostic query/analysis core is kept.
weight: 3
---

# v2 architecture — decoupled JSON API + React SPA

## Why

The v1 dashboard is a Livewire application: every page is a Livewire card,
navigation is `wire:navigate`, and the whole thing ships behind a single ~1 MB
ECharts bundle. It works, and the correlation model behind it is strong — but
the presentation layer fights us where a telemetry product needs to be sharp:

- **Interaction.** Cross-card time-brushing, virtualised tables over thousands
  of rows, live-tail, flame graphs, a topology you can drag — these are
  client-side concerns. Livewire round-trips the server for state that should
  never leave the browser, and `wire:navigate` inserts view transitions and a
  progress bar on every click that read as jank, not speed.
- **Coupling.** View, state, and query live in one PHP class. There is no clean
  seam to build a second consumer against (a mobile view, an embedded panel, a
  status page) short of the read-only MCP server.
- **Delivery.** One un-split bundle that the host's web server must ship whole;
  under `php artisan serve` it truncates under load. A real asset pipeline
  (code-splitting, hashed chunks) is not available to a Blade+Livewire UI.

**Decision: a big-bang v2 that removes Livewire entirely.** The package becomes
a **versioned JSON API** plus a **compiled React single-page app** it serves as
static assets. UI and API are decoupled — they talk only over HTTP — so each can
change independently and a future non-web client is just another API consumer.

This is the mainstream shape for this kind of product: Grafana, Datadog and
Sentry are all a JSON API with a separate JS app in front of it.

## What we keep (≈80% of the code)

The valuable part of v1 is already framework-agnostic and is carried over
unchanged:

- `Contracts/` — `MetricsSource`, `TracesSource`, `LogsSource`, `AggregatesSpans`.
- `Connectors/` — Prometheus/Mimir, Tempo, Loki drivers, `ApiClient`,
  `ConnectionManager`, the ClickHouse store.
- `Queries/Ir/` — the backend-neutral query IR (`MetricQuery`, `TraceQuery`,
  `LogQuery`) and its per-driver compilers.
- `Queries/Results/` — the readonly result DTOs (`TimeSeries`, `Trace`, `Span`, …).
- `Analysis/` — `SignalContext` (trace↔metrics baselines), `RequestReport`,
  `ErrorGroupReport` (grouping + suspect-deploy), `TraceView`, `TraceLogs`,
  `TraceProfile`, `MetricSummary`.
- `Support/` — `Annotations`, `Format`, `ScopeLock`, `MetricScope`,
  `ExceptionFingerprint`, schema detection.
- `Mcp/` — the read-only MCP server (already a headless consumer of the above,
  and proof the core stands on its own).

## What is replaced

- **`Cards/` (Livewire) and `resources/views/` (Blade)** are removed. Each card
  today does two things: build a data array, and pick a Blade view. In v2 the
  data-building becomes an **API endpoint** and the view becomes a **React
  component**. The queries and analysis a card leans on do not move.
- `resources/js/telemetry-ui.js` (the ECharts + Alpine glue) and the
  hand-written `public/telemetry-ui.css` are replaced by the SPA's own build.

## Shape: one package, internal decoupling

Stay **one composer package** (`cboxdk/laravel-telemetry-ui`). A host does
`composer require` and gets, mounted under the configured path:

- `GET  {path}/api/v2/*` — the JSON API.
- `GET  {path}/{any}`     — a catch-all returning the SPA shell (`index.html`),
  whose hashed JS/CSS chunks are served by the existing asset controller from
  `public/`.

The SPA and API are decoupled *internally* (the SPA only ever calls the API over
HTTP), which buys the architectural benefits — independent iteration, a stable
contract, the option to host the SPA elsewhere later — **without** forcing the
host to deploy two apps. Inertia is deliberately not used: it is an app-level
adapter, awkward to ship inside a mountable package, and it re-couples routing
to the server. Plain `fetch` against a versioned API keeps the seam clean.

> Optional, later: split the core out as `cboxdk/laravel-telemetry-query`
> (connectors + IR + analysis) with `laravel-telemetry-ui` depending on it for
> the API + SPA. Worth doing once a second consumer exists; **not** a blocker for
> starting v2.

## The API

- **Versioned** under `/api/v2`. One endpoint per view unit (roughly one per v1
  card): it returns the same data array the card built, as a typed JSON
  resource. Endpoint shapes are the contract the SPA is written against.
- **Auth** is the existing `viewTelemetryUi` gate, moved to route middleware.
  The SPA authenticates with the host session + CSRF (same-origin), so no token
  plumbing for the common case.
- **Scope & tenancy** (`ScopeLock`, `MetricScope`, the service/env/period
  selection) move from Livewire component state into a per-request scope
  resolved from query params + the tenancy resolver — the fail-closed semantics
  are unchanged, just relocated.
- **Live data** (log/request live-tail, `wire:stream`-style updates) becomes an
  **SSE** endpoint the SPA subscribes to; polling is the fallback.
- **Errors** are typed (backend-down vs empty vs forbidden) so the SPA renders
  the right state instead of a blank card.

Extraction discipline: pull each card's data-building into a plain service or
action returning a DTO, then wrap it in a thin controller. Many analyses already
are such services (`SignalContext::for(...)`, `RequestReport`, `ErrorGroupReport`)
and become endpoints almost directly.

## The SPA

- **Stack:** React + Vite + TypeScript. **TanStack Query** for data (caching,
  background refresh, the global period/scope as query keys), **TanStack Router**
  for client-side navigation (instant, no `wire:navigate` transitions), **ECharts**
  for charts (reused knowledge), a virtualiser (TanStack Virtual) for big tables.
- **Base path:** the package mounts under a host-configured prefix; the SPA reads
  it from a bootstrap `<meta>`/global so the router and asset URLs are correct.
- **State that stays client-side:** time-brush selections, table sort/filter,
  open drawers, the command palette — none of it round-trips. URL remains the
  source of truth for shareable state (period, scope, open trace).
- **Delivery:** Vite build → hashed chunks in `public/`, code-split per route, so
  the 1 MB-whole-bundle problem disappears. Assets are committed (as
  `telemetry-ui.js` is today) so hosts need no Node toolchain.

## Dimensions, filters & drill-down (the core new capability)

The biggest product gap in v1 is that a span is a **raw attribute dump** — a
key/value table with no way to filter, group or drill by those keys. A request
carrying `hubhus.customer_id`, `hubhus.campaign_id`, `user.id`, `client.address`
should let you ask "everything for customer 8655", "group errors by campaign",
"which customers hit this 422" — the questions Datadog facets, Honeycomb
dimensions, Sentry tags and New Relic `FACET` all answer. v2 makes **dimensions a
first-class primitive.**

- **Every attribute is a facet.** In any list or detail, each attribute value is a
  control: click to filter to it, ⌥-click to exclude, "group by" to break the view
  down, "top values" for its distribution. This is the drill-down missing on
  customer / user / IP / route / query / view today.
- **Config-declared custom dimensions.** A host registers the attributes that
  matter and how to present them:

  ```php
  TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus',
      link: fn ($id) => route('customers.show', $id));   // optional link OUT
  TelemetryUi::dimension('hubhus.campaign_id', label: 'Campaign', group: 'Hubhus');
  ```

  Declared dimensions get a label, a facet-panel group, optional formatting and an
  optional link back into the host app. They then appear everywhere — the facet
  sidebar, group-by menus, the filter bar, and as clickable chips on every
  trace/request — with no UI code. Undeclared attributes stay filterable (raw
  key), just not promoted.
- **Facet panel + filter bar** (Datadog/Honeycomb): a left panel of dimensions
  with top values and counts; a top bar showing the active query as removable
  chips; the URL *is* the query, so every view is shareable and back/forward works.
- **Feasible on today's core.** Filtering by any attribute is native TraceQL
  (`{ span.hubhus.customer_id = "8655" }`) through the existing IR; group-by /
  top-values is the `AggregatesSpans` capability (exact on the ClickHouse store /
  telemetryd, read-side sample on plain Tempo). No new query engine — a
  `Dimensions` registry + facet endpoints on top of what exists.

## Screens: an Explore surface + entity pages, not a card grid

v1 is fixed cards on fixed pages. v2 has two shapes:

- **Explore** — one surface over traces / requests / logs / errors: filter by any
  dimension, group-by, a distribution/heatmap on top, a virtualised result list
  below, click any row → the trace story. Where ad-hoc questions get answered
  (Datadog APM/Log Explorer, Honeycomb, Sentry Discover).
- **Entity pages** — a route, query, view, host, customer or job is a real page
  that tells a **story**: RED headline + trend, top contributing dimensions,
  slowest/failing example traces, correlated errors and deploys — not a raw
  attribute table. Raw lives behind a "Raw" toggle, last, as the trace waterfall
  already does. Clicking a query, view or route opens *this*, never a dead sidebar
  or a raw dump.

Entity pages are generated, not hand-built per type: an entity is "a dimension
value" (route = `http.route`, query = normalised statement, customer =
`hubhus.customer_id`), so one page template serves all of them, scoped by one facet.

## What v1 gets wrong (explicitly removed in v2)

Symptoms hit in production, and the v2 answer:

- **Raw attribute dumps as "detail."** A span/route/view detail is a key/value
  table. → Entity pages lead with the story; raw behind a toggle.
- **No drill-down / dimension filters** (customer, user, IP, campaign). → Facets +
  filter bar + config dimensions above.
- **Destructive toggles.** The request-log toggle *replaces* the routes table on
  the Requests page. → Explore is its own surface; routes and log are distinct, not
  a mode-swap that hides one.
- **Dead detail panes.** Clicking a route shows a sidebar with useless content;
  queries/views aren't clickable at all. → Every row opens a real entity page.

These are consequences of the fixed-card, drawer-first Livewire model — which is
why v2 is a rewrite, not a patch.

## Migration mechanic

For each v1 card:

1. Extract its `render()` data-building into a service returning a DTO (leave the
   queries/analysis it calls untouched).
2. Expose the DTO as a JSON resource on a `/api/v2` route.
3. Write the React component that fetches it and renders it.

The hard, already-solved parts — the IR, the connectors, the correlation
(`Analysis/*`) — are consumed as-is. This is a systematic transform of the view
layer, not a re-think of the product.

## Phased plan

Big-bang in outcome, de-risked in sequence — **the API ships and is proven before
the SPA exists.**

1. **Extract & define.** Move card data-logic into framework-free services/DTOs;
   nail down the `/api/v2` resource shapes. No behaviour change yet.
2. **API layer.** Ship the versioned JSON API + gate middleware + scope context +
   the SSE endpoint, **running alongside the v1 Livewire UI**, so it is validated
   against real backends (the MCP server already exercises the core the same way).
3. **SPA.** Build the React app page by page against the live API — start with
   Traces / a cross-signal Explore view (the most interaction-heavy, where the
   new stack pays off first).
4. **Cut over.** Delete `Cards/`, `resources/views/`, Livewire, the old JS/CSS.
   Tag **v2.0**. Keep a maintained **`1.x` branch** for hosts that cannot move yet.

## Where the "Datadog/Sentry replacement" work goes

Richer grouping, log parsing, aggregation, SLO/monitors, exemplars (metric→trace)
are **backend** features. They belong in the query/analysis core and reach every
consumer (SPA, MCP, future clients) without frontend churn. The frontend rewrite
does not, by itself, deliver them — plan them as core work, in parallel.

## Risks & mitigations

- **Long no-ship window** (the classic big-bang failure). Mitigate: keep `1.x`
  releasable throughout; ship the API in phase 2 so value lands before the SPA is
  done; build the SPA route-by-route, not all-at-once.
- **Losing mature features.** v1 carries a lot that is easy to under-estimate: the
  stacked, deep-linkable correlation drawer; the command palette; deploy
  annotations on every chart; suspect-deploy on error groups; the request "story";
  the service graph; whole-row drill-down. Write an explicit **feature-parity
  checklist** and treat it as the cut-over gate — do not delete Livewire until it
  is green.
- **SPA-in-a-package edges.** Base-path routing, same-origin auth/CSRF, and CSP
  for the served assets are all solvable but must be designed up front, not
  discovered during cut-over.

## Feature-parity checklist (cut-over gate)

Status on `feat/v2-spa` (2026-09-22). "Live" = verified in the browser against
telemetryd via the demo; "tests" = covered by Pest (API) and/or Vitest (SPA).

- [x] Every v1 page has an endpoint + component at parity — all ~120 cards are
      `Panels\Builtin\*` behind `/api/v2/panels/{id}`, rendered by the SPA's
      kind renderers; PagesSmokeTest hits every page and panel. Live: dashboard,
      jobs, traces, exceptions, route/customer entities. Detail pages became the
      entity pages' Metrics tab.
- [x] Global scope (service/env/period, custom range, tenancy lock) + URL state —
      `RequestScope` + `ScopeLock` (ScopeLockTest); custom range popover and
      brush-to-zoom write `from`/`to`; remembered via `POST /view-state`. Live.
- [x] Stacked, deep-linkable trace/issue/exception drawer — `?drawer=error:…~trace:…`,
      back/crumbs/Esc/full-page. Live.
- [x] Correlation: trace → surrounding metrics (baselines/outliers), trace → logs,
      trace → profile, error group → trace/release/host + suspect deploy —
      `/traces/{id}` and `/errors/{group}` (TraceDrawerTest); trace Story/Context/
      Logs/Profile tabs; entity stories add correlated error groups + deploys. Live.
- [x] Deploy/change annotations on charts — marker lines on every panel chart and
      entity trends; Deploys panel. Live (annotate command).
- [x] Command palette (⌘K), whole-row drill-down, copy-link/deep-links — palette
      (pages, scope, trace id, error group, `key=value` filters, entity jump);
      `_link` rows; copy-link button. Live + tests.
- [x] Live-tail (logs, request log) via SSE — `/stream/{logs|requests}` with
      `Last-Event-ID` resume and polling fallback (StreamTest); panel "Tail" polls.
      Live (logs).
- [x] Schema-detection driven nav + host-registered nav links — bootstrap nav is
      detection- and gate-filtered (SchemaDetectionTest, NavLinkTest); links in the
      rail foot.
- [x] Read-only MCP server unaffected (it consumes the core, not the UI) —
      McpToolsTest unchanged and green.

New in v2 beyond parity: dimensions registry, facet panel, filter bar (URL is the
query), group-by, drill-down on every value, generated entity pages with a story
and Raw tab last.

## Open decisions

- Same-origin session auth only, or also a token mode for an externally-hosted SPA?
- Split the query core into its own package now, or after v2 ships?
- SSE only, or WebSockets where a host runs Reverb?
