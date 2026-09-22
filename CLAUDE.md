# CLAUDE.md

Laravel package: observability dashboard querying Tempo (TraceQL), Loki
(LogQL) and Prometheus/Mimir (PromQL) directly. Companion to
`cboxdk/laravel-telemetry` (../laravel-telemetry), whose metric names and
span attributes this UI hardcodes knowledge of — see
docs/design/direction.md for the screen → query mapping. v2 (branch
`feat/v2-spa`) is a versioned JSON API + React SPA; Livewire is gone — see
docs/design/v2-architecture.md.

## Commands

- `composer check` — pint --test, phpstan (level 8), pest. Must pass.
- `composer format` / `composer analyse` / `composer test` individually.
- `npm test` — vitest (SPA unit tests). Must pass.
- `npm run build` — typecheck + vite build of resources/app into public/build
  (hashed chunks + .vite/manifest.json, committed).

## Architecture (src/)

- `Contracts/` — MetricsSource, TracesSource, LogsSource (+ optional
  AggregatesSpans, EnumeratesMetricNames, IssuesSource). Panels depend only
  on these.
- `Connectors/` — ApiClient (Laravel Http, X-Scope-OrgID tenancy), drivers
  (Prometheus, Mimir=Prometheus+prefix, Tempo, Loki), lazy ConnectionManager
  (named connections from config, `extend()` for custom drivers). Drivers
  parse raw API JSON into readonly DTOs in `Queries/Results/`; queries are
  built as IR (`Queries/Ir/`) and compiled per dialect.
- `Panels/` — framework-free `Panel` base (constructed with a RequestScope,
  `data(): array` returns a typed payload, `#[Param]` binds query params) and
  `Ui` (payload + link builders, mirrored by resources/app/src/api/types.ts).
  Built-ins in `Panels/Builtin/` (detail panels + `ScopesTo*` traits in
  `Builtin/Detail/`).
- `Dimensions/` — `Dimension` + `Dimensions` registry (built-ins +
  host-declared via `TelemetryUi::dimension()`).
- `Explore/` — SpanExplorer (requests/traces), LogExplorer, ErrorExplorer,
  EntityStory (entity pages), Stats. Facets exact via AggregatesSpans, else a
  labelled read-side sample.
- `Http/Api/` — RequestScope (scope from query params, bounded by ScopeLock),
  Filter (`where[]=key<op>value`), ApiError (typed errors), Json, Serializer.
- `Http/Controllers/Api/` — one thin controller per `/api/v2` endpoint
  (routes/web.php). `SpaController` serves the shell for every other path;
  `AssetController` serves public/build.
- `TelemetryUiManager` — page/panel/dimension/entityPage registry
  (class-strings only). Facade: `TelemetryUi`. Pages with a `detect`
  metric-name pattern are autodetected via `Support/SchemaDetector` (one
  batched, cached lookup, fail-open); the built-in Statamic sidebar group
  (from ../statamic-telemetry) has subpages that each detect their own
  `statamic_*` family.
- `resources/app/` — the React SPA (Vite, TS, TanStack Query/Router/Virtual,
  ECharts lazy chunk). Talks to the API over plain fetch only.
- Routes gated by `viewTelemetryUi` (local-only default; per-page second
  argument), `manageTelemetryUi` for writes.

## Hard rules

- Boot hygiene: the service provider must never do I/O or instantiate
  connectors — registrations only. Enforced by convention + arch tests.
- PHPStan level 8, `declare(strict_types=1)` everywhere, Pest 4 for tests
  (Http::fake with realistic backend payloads — see tests/Feature).
- Panels must never throw from `data()`: catch SourceException and return the
  payload with an `error` key.
- After any change under resources/app, run `npm run build` and commit
  public/build — hosts install without Node.
- Follow conventions of ../laravel-telemetry (same author, same tooling).
