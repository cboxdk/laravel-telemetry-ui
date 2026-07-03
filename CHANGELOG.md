# Changelog

All notable changes to `cboxdk/laravel-telemetry-ui` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `php artisan telemetry-ui:check` — probes each configured connection with its
  cheapest read and reports OK/FAIL/not-configured; exits non-zero on failure
  so it doubles as a deploy healthcheck.

### Fixed

- Linear now surfaces GraphQL errors (auth/permission/query failures, which
  Linear returns as HTTP 200 with an `errors` array) as a `SourceException`
  instead of silently returning an empty issue list.

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
