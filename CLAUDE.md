# CLAUDE.md

Laravel package: observability dashboard querying Tempo (TraceQL), Loki
(LogQL) and Prometheus/Mimir (PromQL) directly. Companion to
`cboxdk/laravel-telemetry` (../laravel-telemetry), whose metric names and
span attributes this UI hardcodes knowledge of — see
docs/design/direction.md for the screen → query mapping.

## Commands

- `composer check` — pint --test, phpstan (level 8), pest. Must pass.
- `composer format` / `composer analyse` / `composer test` individually.
- `npm run build` — rebuild public/telemetry-ui.js (ECharts bundle, committed).
  public/telemetry-ui.css is hand-written, not built.

## Architecture (src/)

- `Contracts/` — MetricsSource, TracesSource, LogsSource. Cards depend only
  on these.
- `Connectors/` — ApiClient (Laravel Http, X-Scope-OrgID tenancy), drivers
  (Prometheus, Mimir=Prometheus+prefix, Tempo, Loki), lazy ConnectionManager
  (named connections from config, `extend()` for custom drivers). Drivers
  parse raw API JSON into readonly DTOs in `Queries/Results/`.
- `Cards/` — Livewire base `Card` (period via `?period=` +
  `telemetry-ui:period-changed` event, source accessors, `toChartSeries()`).
  Built-in cards in `Cards/Builtin/`.
- `TelemetryUiManager` — page/card registry (class-strings only). Facade:
  `TelemetryUi`. Pages with a `detect` metric-name pattern are autodetected
  via `Support/SchemaDetector` (one cached `count({__name__=~"..."})` query,
  fail-open); the built-in Statamic sidebar group (from ../statamic-telemetry)
  has subpages that each detect their own `statamic_*` family
  (`statamic_static_cache.*`, `statamic_stache.*`, `statamic_glide.*`, etc.).
- Routes gated by `viewTelemetryUi` gate (local-only default); assets served
  from `public/` by AssetController, no publishing.

## Hard rules

- Boot hygiene: the service provider must never do I/O or instantiate
  connectors — registrations only. Enforced by convention + arch tests.
- PHPStan level 8, `declare(strict_types=1)` everywhere, Pest 4 for tests
  (Http::fake with realistic backend payloads — see tests/Feature).
- Follow conventions of ../laravel-telemetry (same author, same tooling).
