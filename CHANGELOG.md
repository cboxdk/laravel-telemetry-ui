# Changelog

All notable changes to `cboxdk/laravel-telemetry-ui` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
