# Changelog

All notable changes to `cboxdk/laravel-telemetry-ui` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Users affected.** Error groups now count distinct users (from the
  `enduser.id` laravel-telemetry ≥ alpha.18 stamps on exception records):
  a Users column on the errors list and a "users affected" fact on the
  group panel.
- **Errors list filters.** Free-text filter over type/message and a
  source selector (server / web / full-stack), both URL-synced.
- **Scope moved to the top header.** Service and environment now sit
  next to the period picker on every page — Sentry's top-bar pattern —
  and the sidebar is pure navigation.
- **Full-color card borders.** The context tiles and root-cause box wear
  their accent color as a full border instead of a left edge.
- **Sentry-style errors list.** Every group row now carries its in-period
  trend sparkline, first seen and last seen, a **NEW** badge for groups
  born within 24 hours, and a sort control (events / last seen / first
  seen). First-seen looks beyond the page period (min 7 days) so it means
  what it says; counts and trends stay period-scoped.
- **Root-cause hints on the error-group panel.** The change event (deploy,
  migration, feature flag, …) closest before the group's first occurrence
  — within 48h — is named as the suspect ("Deploy v9.1.0 at 14:02 — first
  seen 18 minutes later"), and the panel shows which releases the sampled
  occurrences carry, with an "only this release" badge when every one
  points at a single release.

## [0.1.0-alpha.4] - 2026-07-06

### Added

- **Error groups live on the Issues page too.** The unified Errors card
  now sits above the tracker list — "what's broken" and "what's filed"
  on one page.
- **Issue/PR bodies render as formatted markdown.** Dependabot's
  release-notes blocks, headings, lists, code and links display like on
  the tracker instead of raw tag soup — via a strict-allowlist sanitizer
  (structural tags only, every attribute dropped except validated http(s)
  hrefs; scripts/styles/iframes lose their payload entirely), because
  tracker bodies are external content.
- **Annotations are now interactive.** Each chart marker line carries a
  colored dot handle; hovering it opens a callout ANCHORED to the line
  (the pointer can move into it), and clicking pins the same callout in
  the same place — one shape, one position, both triggers. It shows the
  label, exact time, notes and an "Open trace" button into the emitting
  trace.
- **Rollout markers cluster.** A horizontal deployment emits the same
  marker from every host within minutes; 200 servers no longer draw 200
  lines. Same-kind+label events within a 15-minute gap window fold into
  one marker showing ×N, the rollout span (first → last host) and the
  covered hosts — on charts, in the callout and on the Deploys timeline.
- **Host detail page** — clicking a host (from the Hosts list or the trace
  context strip) opens its own page: headline CPU/memory/load/request stats,
  host-scoped system charts (CPU load, memory, network, filesystem), and a
  **Services on this host** card fed by the services' own Prometheus
  exporters — mysqld_exporter, redis_exporter, postgres_exporter and
  node_exporter probes ship as defaults, plus an app-side Redis section
  that needs no exporter at all. Config-driven (`telemetry-ui.host-services`,
  `{host}` token): a probe that returns nothing simply doesn't render, so
  listing exporters you don't run is free — and adding your own is a
  config entry, not code.
- **The trace context strip names its scope.** The host/runtime tiles now
  say exactly whose signals they show — the `host.name` that served the
  trace (linked to the Hosts page) and the service, or "all hosts" when
  the resource carries no host and the queries aggregate service-wide.
  `host.name` also joined the resource rows in the span attribute panel.
- **Exception groups link back to the request.** The error-group panel now
  shows env / release / host facts off the exception record (host links to
  its detail page), and a "Latest occurrence" strip off the newest trace
  root — method + route (linked to the route's detail page), status, user
  and the request trace. Occurrence rows are whole-row click targets.
- **The drawer is now a docked properties pane on wide screens.** At
  ≥1100px it pushes the page aside instead of covering it — no backdrop,
  the page stays fully interactive, and selecting another row simply swaps
  the pane's content (links *inside* the pane still stack with
  back-navigation). Narrow screens keep the overlay behavior.
- **"Database (seen by app)" host section** — the host page now shows
  database activity (queries/s, hourly volume, N+1 detections) from
  laravel-telemetry's new `db.queries` counter, no exporter required.
  MySQL/Postgres exporter probes remain the path to real health stats.
- **Host services tell the truth about visibility.** App-side sections
  (like "Redis (seen by app)") carry an `observed` badge instead of
  up/down — traffic measured by the app proves usage, not health — plus a
  note pointing at the exporter that would unlock full monitoring.
- **The drawer opens instantly.** Clicking a trace/issue/error row slides
  the drawer in immediately with a shimmer skeleton; the content morphs in
  when the backend queries land — no more click lag.

### Fixed

- **Charts no longer stick at the width they measured mid-render.** The
  ECharts instance now lives outside Alpine's reactive proxy (a proxied
  instance silently breaks `resize()`), and a `ResizeObserver` keeps the
  layout in step with the container — Livewire streaming a card in at
  zero width, sidebar/drawer toggles and orientation changes all heal.

## [0.1.0-alpha.3] - 2026-07-06

### Added

- **Mobile-friendly layout** — below 768px the sidebar becomes an off-canvas
  drawer behind a sticky topbar hamburger, header controls wrap, the trace
  waterfall drops the per-row service chip so span names stay readable, the
  custom-range popover anchors to the viewport, the trace drawer goes
  full-width, and inputs are sized to avoid iOS focus zoom.
- **laravel-telemetry v0.1.0-alpha.16 support** — five new auto-detected
  sidebar pages plus cards, each appearing only when the fleet emits the
  signals:
  - **Horizon** (`horizon_*`): worker processes per supervisor with
    paused/supervisor gauges, and an incidents chart (long waits, restarts,
    OOM kills, migrated jobs).
  - **Reverb** (`reverb_*`): active WebSocket connections per app with
    subscribers by channel type and pruned-connection counts, plus message
    throughput sent vs received.
  - **Feature Flags** (`feature_*`, Pennant): checks by flag with active
    share and per-result badges, and a warning strip for checks against
    unregistered flags.
  - **Storage** (`storage_operations_*`): Flysystem disk operations per
    minute by type, with per-disk totals.
  - **Livewire** (`livewire_*`): mounted vs hydrated components per minute,
    and a slowest-components table off the render/update/call detail spans.
  - **Rate limiting** card on the Requests page: 429s per minute by limiter.
  - **Core Web Vitals** card on the Frontend page: real-user p75 LCP / CLS /
    INP per path from the SDK's `web-vitals` spans, toned on Google's
    thresholds.
  - **Duplicate queries (N+1)** card on the Queries page, from the
    `db.query.duplicate_detected` log events — query text, traces affected,
    worst repeat count, trace link.
  - **CPU profile strip** on the trace view: when excimer captured a profile
    for the trace, the waterfall is headed by top functions by CPU share.
  - **Span links** (queue retries): linked traces render as clickable rows in
    a span's attribute panel.
- **Error-group detail drawer (Sentry-style issue view)** — clicking a row on
  the unified Errors card now opens a drawer with the exception's message,
  occurrence stats (count, first/last seen, source), the latest occurrence's
  **stacktrace and source context**, a prefilled "+ ticket" compose button and
  a recent-occurrences table whose trace links stack onto the drawer.
  Deep-linkable via `?exception=<group>`; searches are forced inside the
  viewer's tenancy scope lock.
- **Cache purge annotations** — two new built-in markers: `cache_purge`
  (app-agnostic, emit via `php artisan telemetry-ui:annotate cache_purge
  --id=redis`) and `statamic_cache_purge`, which matches the
  `statamic.cache.purge` events cboxdk/statamic-telemetry emits on every
  stache/static/glide clear — so Statamic purges show up as chart lines and
  timeline events with no wiring.

- **Issues across multiple repos** — `connections.issues` may now be a list of
  trackers (frontend, api, sidecar, …), each with a `label`. The Issues page
  aggregates them newest-first, tags each row with its repo, and adds a repo
  filter. A single connection still works unchanged.
- **Manual refresh button** in the toolbar, next to the auto-refresh control.
- **Trace list reads like requests** — rows now show the HTTP method, route and
  status (error-highlighted) off the root span, plus a `web` badge for RUM
  traces, instead of a bare span name.
- **Row drill-down on Analytics & Frontend** — a page row opens every trace for
  that path, which spans the browser page load *and* the backend request it
  triggered (frontend → backend in one waterfall).

### Changed

- Empty states on Analytics/Frontend now hint that the data lives under the
  app's own service, so an empty page nudges you to check the service scope.

### Security

- **Scope lock is now enforced on raw trace queries.** A hand-edited or
  deep-linked `?q=` on the Traces page ran verbatim, bypassing the tenancy
  lock; it is now forced into the viewer's allowed services (drill-down links
  go through the scoped builder too).
- **Scope lock fails closed.** A viewer whose resolver returns an empty allowed
  set now matches nothing instead of the whole fleet, and deploy-marker queries
  respect a multi-value lock (previously left unscoped).

### Fixed

- **Assets are exempt from the dashboard throttle.** The JS/CSS bundle sat
  behind the same `telemetry-ui.throttle` (120/min per IP) as the pages, so
  a busy dashboard — auto-refresh, several tabs, a shared office IP — could
  429 the bundle itself and take every chart down
  (`telemetryUiChart is not defined`). Version-stamped immutable assets now
  skip the throttle, the same way they already skip the auth gate.
- **The unified Errors card now works against real data.** It searched Tempo
  for `span.exception.group`, but laravel-telemetry records backend exceptions
  as span *events* (and as structured log records) — the attribute never
  exists on spans, so the card was permanently empty in production. It now
  reads the structured exception records from Loki (authoritative — present
  even when the trace is sampled away) and merges in browser exception spans
  from Tempo, fingerprinted read-side with the same algorithm the backend
  uses (browser ingest doesn't stamp `exception.group`).
- Deploy-marker annotations are fetched in a single Loki query instead of one
  per marker type (6+ round trips) — much cheaper on every chart card.
- `TelemetryUi::setCards()` / `removeCard()` now affect the **dashboard** page's
  config-declared cards (they previously no-op'd there).
- A misconfigured repo in a multi-repo `connections.issues` list is skipped
  instead of 500-ing the Issues page; a partial per-tracker failure shows a
  warning banner rather than silently dropping that repo.
- Per-tenant connection config is keyed safely (no fatal when a resolver returns
  a closure/resource), memoised per request, and `hasIssues()` is resolver-aware
  so page/gate decisions match the tracker actually resolved.
- The brand accent value can no longer smuggle an external `url(...)`, and an
  array-shaped `?service[]=` no longer corrupts the `DashboardViewed` audit scope.

### Added

- **Integration events** — `DashboardViewed` (user + page + scope, for audit and
  usage metering) and `BackendQueried` (url + method + duration + ok, for backend
  load metering per tenant) let a host hook the dashboard without patching it.
- **Branding / white-label** — `telemetry-ui.brand` config sets the sidebar
  name, logo and accent colour; views are namespaced (`telemetry-ui::`) so a
  host can override any of them.
- **Per-tenant backends** — `TelemetryUi::resolveConnectionsUsing(fn ($user) => [...])`
  resolves connection config per viewer, so a hosted multi-tenant install can
  point each tenant at their own Mimir/Tempo/Loki (or a shared backend behind a
  per-tenant `X-Scope-OrgID`). Omitted connections fall back to the static
  config; drivers are cached by config, so one tenant never gets another's under
  Octane. See [authorization](docs/core-concepts/authorization.md#per-tenant-backends).
- **Tenancy scope lock** — `TelemetryUi::restrictScopeUsing(fn ($user) => [...])`
  locks a viewer to a subset of services and/or environments, for embedding the
  dashboard in an app. The scope switcher only offers the allowed values and
  **every query is forced into the lock** server-side — a blank or hand-edited
  `?service=` can't widen past it (one allowed service → `service_name="x"`,
  several → a `service_name=~"a|b"` alternation), across metrics, traces and
  logs. Resolved per request. See the [authorization doc](docs/core-concepts/authorization.md#tenancy-lock-a-viewer-to-services--environments).

## [0.1.0-alpha.2] - 2026-07-05

### Added

- **Analytics page (visit analytics)** — a privacy-first traffic dashboard built
  on the emitter's unsampled `analytics.page_view` stream: a page-views trend
  chart (with deploy annotations), **unique visitors** (the cookieless daily
  session hash — no cookies, no stored IP), views-per-visit, **bounce rate**
  (single-page-view sessions) and **average engagement time** (from
  `analytics.engagement` events), top pages with distinct visitors, and a
  sources/audience
  breakdown (referrers, and — when the emitter's geo/User-Agent enrichment is on
  — countries and devices). Trace/Loki-sourced so it's exact for low-traffic
  sites and a bounded sample at scale (the eventual answer being a ClickHouse
  sink behind the same cards). Also: the Loki driver now surfaces per-entry
  **structured metadata**, so high-cardinality OTLP log attributes (the visit
  dimensions) are readable instead of dropped.
- **Frontend page (RUM)** — a new Monitoring page for real-user browser data:
  **Page performance** (navigation timings per page — loads, avg load, TTFB,
  DOM-interactive, from the `document.load` spans) and **Failed browser
  requests** (fetch/XHR calls that 5xx'd or errored, grouped by URL, each row
  opening a representative trace where a same-origin failure continues into the
  backend span that caused it). Trace-sourced (no RUM metric exists), bounded
  sample.
- **Unified errors list (frontend + backend)** — a new lead card on the
  Exceptions page groups every error by `exception.group`, the Sentry-style
  fingerprint (class + top in-app frame) that both the backend handler and the
  browser SDK stamp with the *same* algorithm. A JS `TypeError` and a PHP
  exception that are "the same issue" collapse into one row tagged
  `web`/`server`/`full-stack`, with an occurrence count and last-seen; clicking
  a row opens a representative trace (→ waterfall + host context), and "all"
  jumps to every trace for that fingerprint. Trace-sourced (metrics can't unify
  — frontend errors exist only as spans), so counts are over a bounded recent
  sample.
- **Frontend / RUM spans in the unified trace** — browser spans emitted by
  cboxdk/laravel-telemetry's frontend proxy (alpha.6/7) now read as first-class
  frontend rows. They share the backend's `service.name`, so the per-span
  server-stamped `browser=true` attribute is the marker: browser spans get a
  `web` badge in the waterfall, `document.load` shows its RUM timings (`TTFB`,
  `DOM`), and browser `fetch` spans render their URL + status. Trace search
  gains a **Source** filter (frontend/backend) that scopes on `span.browser`.
  Because the browser continues the backend's `traceparent`, a page load, its
  fetches and the server spans they trigger already nest into one waterfall —
  end-to-end frontend→backend on open data.

- **Dimensional drill-down / filtering (Grafana-style)** — every span/resource
  attribute in the trace properties window (host, user, team, client IP,
  deployment, method — whatever the app emits) is a click-to-filter link that
  scopes Traces to `{ .key = "value" }`. Plus a new **Hosts** page listing
  every host/server reporting telemetry (request volume, errors, CPU, memory),
  each row filtering requests to that host.
- **Purpose-built detail pages with progressive drill-down** — clicking a row
  opens a dedicated detail page instead of a pre-filtered trace search, à la
  Nightwatch, for **routes, jobs and exceptions**. Each shows the entity's own
  numbers scoped to it, and drills deeper: a route detail goes throughput →
  latency → *exact status codes* → its individual traces (→ waterfall + host
  context). Built on a "hidden page" concept (routable + rendered, out of the
  sidebar), a `scopeMatchers()` card hook and per-entity `ScopesTo*` traits, so
  the overview cards are reused scoped to one entity — the pattern extends to
  hosts, queries, etc. cheaply.
- **MCP server** — `php artisan mcp:start telemetry-ui` serves metrics, traces,
  logs and the correlation/analysis tools over the Model Context Protocol,
  built on the first-party `laravel/mcp` package, so an agent (Claude Desktop,
  Cursor, …) can query the stack directly for incident RCA. Six read-only
  `Server\Tool`s, including `trace_context` (a trace plus the host/runtime
  signals around it, flagged against normal). Same read drivers the dashboard
  uses.
- **Remote MCP over HTTP with OAuth + DCR** — set `TELEMETRY_UI_MCP_WEB=true`
  (and install `laravel/passport`) to expose the server over HTTP behind
  `auth:api`, with the OAuth 2.1 authorization server and **Dynamic Client
  Registration** endpoint that `laravel/mcp` provides — no custom OAuth code.
  Off by default; Passport stays optional.
- **Signal correlation** — a trace now shows the host and runtime signals
  recorded around it (CPU, load, memory, network, process RSS) in a context
  strip beside the waterfall, scoped by service + host and the trace's time
  window. This is the thing an app-only monitor can't do: the same Prometheus
  scrapes `system_*`/`process_*` — and node_exporter, mysqld_exporter, … when
  present — right next to the app. Config-driven and fail-open per signal
  (`telemetry-ui.context.signals`); a new headless `Analysis\SignalContext`
  is the reusable foundation.
- **"What was different"** — each context signal also carries its baseline (the
  typical value for that scope over a longer lookback), so a tile reads "Host
  CPU 95% (typical 30%)" and flags outliers. Answers the "was the box busted?"
  question at a glance, without ML — just an honest comparison to normal.

- `php artisan telemetry-ui:check` — probes each configured connection with its
  cheapest read and reports OK/FAIL/not-configured; exits non-zero on failure
  so it doubles as a deploy healthcheck.
- **Annotation writing** — `php artisan telemetry-ui:annotate <marker>` emits a
  marker (deploy, incident, scaling, migration, feature, version — or your own)
  through the telemetry pipeline into Loki, where it renders as a vertical line
  on every chart. No local state: the same store the dashboard already reads.
  `cboxdk/laravel-telemetry` is now a hard dependency (it provides the write
  path, and the dashboard instruments its own stack).
- **Proactive auto-version annotations** — `php artisan telemetry-ui:scan-versions`
  (schedule it) detects a `laravel_version` that's live in the metrics but
  un-annotated and marks it, so an un-announced deploy still lands on the
  charts. Stateless: it dedups against the version annotations already in Loki.
- Whole-row click targets on the routes, jobs, facet, slow-query, trace-search,
  outgoing and exceptions tables — the entire row drills into the matching
  traces (or opens the trace drawer / matching issues), not just the small
  link. cmd/ctrl-click opens in a new tab. Outgoing rows filter traces by
  `server.address`; exception rows jump to their matching issues (or the
  scoped error traces when no tracker is configured).

### Changed

- Dashboard cards now stream in (lazy `on-load`) instead of rendering eagerly,
  so the page shell paints instantly and a slow backend query on one card no
  longer blocks the whole page; each card loads in its own parallel request.

### Fixed

- Chart hover tooltips and drag-to-zoom both work now. The dataZoom brush was
  kept permanently armed for drag-select, which put every chart in select-mode
  and suppressed hover tooltips — so "no data on hover" and "zoom broken" were
  the same bug. Replaced with a raw zrender drag-select (own selection band),
  so hovering shows values (`trigger: 'axis'`) and dragging realigns the range.
- Linear now surfaces GraphQL errors (auth/permission/query failures, which
  Linear returns as HTTP 200 with an `errors` array) as a `SourceException`
  instead of silently returning an empty issue list.
- Prometheus/Mimir non-finite values: `NaN`/`+Inf`/`-Inf` (which Prometheus
  serializes as strings) were cast to a misleading `0.0`. They are now dropped
  so gauges/ratios show a gap instead of a false zero, and the `scalar`
  result branch no longer risks a raw `TypeError` past the `SourceException`
  boundary.
- Fleet (sidebar service/environment) cache TTL is now config-wired
  (`telemetry-ui.fleet.ttl` / `TELEMETRY_UI_FLEET_TTL`), matching the other
  cache TTLs.

### Changed

- `ConnectionManager::client()` is now public so custom drivers registered via
  `extend()` can reuse the configured `ApiClient` (auth, tenancy, cache,
  retries) instead of building one by hand.

### Security

- **Finer-grained authorization.** The `viewTelemetryUi` gate is now **re-run on
  Livewire updates** (card/drawer actions POST to `/livewire/update`, which
  previously skipped it — the gate was only enforced at page load). The gate
  also receives the **page slug**, so an app can restrict individual pages
  (e.g. the PII-heavy Logs/Users) without closing the whole dashboard — denied
  pages 403 and drop from the sidebar/palette. And a new **`manageTelemetryUi`**
  ability gates write actions (creating tracker issues), checked server-side and
  hiding the compose UI, so a read-only viewer can't file tickets (it falls back
  to the view gate, so existing setups are unchanged). See the new
  [authorization doc](docs/core-concepts/authorization.md).
- Backend failures no longer leak the internal endpoint, query string or raw
  response body to the dashboard. `SourceException` now carries a generic
  user-facing message and a separate detail; `ApiClient` logs the full detail
  server-side (the dashboard gate may be opened to semi-trusted operators).
- The MCP web transport throws at boot when OAuth is enabled but
  `laravel/passport` is absent, instead of registering a half-configured
  authorization server. `mcp.web.middleware` documents that `auth:api` is the
  only guard on that endpoint.
- MCP tools are bounded (row/series/window/limit caps + a dedicated throttle),
  `tagValues` lookups carry a time window + limit, and the annotation writer
  can no longer crash a command or the scan-versions cron on an emit failure.

### Documentation

- New [configuration reference](docs/core-concepts/configuration.md) (every key
  + env var), [signal correlation](docs/core-concepts/correlation.md) and
  [custom detail pages](docs/extension-points/detail-pages.md) guides; README
  and roadmap rewritten for the correlation/MCP/annotations/drill-down surface.

### Verified

- GitHub and Linear issue read **and** create paths exercised end-to-end
  against the live APIs. Sentry remains fixture-tested only — see the
  verification-status table in the issue-trackers docs.

## [0.1.0-alpha.1] - 2026-07-03

First alpha. A Livewire + ECharts observability dashboard querying Tempo
(TraceQL), Loki (LogQL) and Prometheus/Mimir (PromQL) directly — a companion
to `cboxdk/laravel-telemetry`.

### Added

- Connector layer with `MetricsSource`/`TracesSource`/`LogsSource`/`IssuesSource`
  contracts and Prometheus, Mimir, Tempo, Loki, GitHub, Sentry and Linear
  drivers, resolved lazily through a `ConnectionManager` with `extend()`.
- Full Nightwatch-inspired information architecture: dashboard, requests, jobs,
  commands, schedule, exceptions, queries, cache, outgoing, mail, users, logs,
  system and traces pages, with a service/environment scope switcher.
- Trace waterfall with infra-chain nesting, drag-to-zoom charts that adapt
  sampling, Loki-backed deploy annotations, facet views, sparklines, a command
  palette and stacked slide-in drawers.
- Issue trackers as a fourth signal, with create-a-ticket-from-an-exception
  for GitHub and Linear.
- Schema autodetection (e.g. the built-in Statamic page) via metric presence.
- Short-TTL query cache: decoded backend GET responses are cached for
  `telemetry-ui.cache.ttl` seconds (default 5, override per connection) so a
  busy dashboard with many cards and auto-refresh does not hammer
  Prometheus/Tempo/Loki. Only plain arrays are cached, never DTOs.
- Transient-blip retry on backend connections (`telemetry-ui.retries`).
- Rate limiting on the dashboard routes via `telemetry-ui.throttle`
  (default `120,1`).
- CI: run-tests (PHP 8.3–8.5 × Laravel 12/13, lowest/stable), PHPStan level 8
  and Pint workflows.
