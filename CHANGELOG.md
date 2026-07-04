# Changelog

All notable changes to `cboxdk/laravel-telemetry-ui` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Signal correlation** — a trace now shows the host and runtime signals
  recorded around it (CPU, load, memory, network, process RSS) in a context
  strip beside the waterfall, scoped by service + host and the trace's time
  window. This is the thing an app-only monitor can't do: the same Prometheus
  scrapes `system_*`/`process_*` — and node_exporter, mysqld_exporter, … when
  present — right next to the app. Config-driven and fail-open per signal
  (`telemetry-ui.context.signals`); a new headless `Analysis\SignalContext`
  is the reusable foundation.

- `php artisan telemetry-ui:check` — probes each configured connection with its
  cheapest read and reports OK/FAIL/not-configured; exits non-zero on failure
  so it doubles as a deploy healthcheck.
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
