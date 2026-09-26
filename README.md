# Laravel Telemetry UI

**Grafana-replacement observability UI for Laravel** — queries your existing
Tempo (traces), Loki (logs) and Prometheus/Mimir (metrics) directly. No
agent, no vendor cloud, no data leaves your infrastructure. The presentation
counterpart to
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry),
schema-aware of every metric and span attribute it emits.

> **v2** replaces the Livewire UI with a versioned JSON API and a React app the
> package serves itself. Coming from 1.x? Read [UPGRADE.md](UPGRADE.md) and the
> [CHANGELOG](CHANGELOG.md) first.

## Why not just Grafana?

Grafana is generic; this dashboard knows what a Laravel app *is*. Routes,
jobs, scheduled tasks, queries, cache stores and users are first-class
concepts, cross-linked across signals — click a slow route, see its traces;
open a trace, see the queries, the trace-correlated logs, **and the host it ran
on**. And it lives inside your app: your auth, your gate, and actions a
read-only dashboard can't do (open a ticket from an exception, talk to it over
MCP).

## Highlights

- **JSON API + SPA** — a versioned JSON API under `{path}/api/v2` and a React
  app that talks to it over plain `fetch`. The built app is committed to
  `public/build` and served by the package, so hosts need **no Node toolchain**.
- **Explore** — one surface over requests, traces, logs and errors: filter by
  any attribute (`where[]=http.route=/checkout`), group by any key, a
  time × latency heatmap on top, a virtualised result list below.
- **Dimensions** — declare the attributes that matter to you
  (`TelemetryUi::dimension('billing.customer_id', label: 'Customer')`) and they
  show up as facets, group-by options, filter chips and clickable chips, with
  an optional link back into your app. Show names instead of ids
  (`TelemetryUi::resolve('user.id', User::class, 'name')`), or read a dimension
  out of another attribute when a routing layer encodes it there
  (`from: 'http.route', pattern: 'portal:{value}'`).
- **Entity pages** — a route, query, job, host, user or customer is a page that
  tells a story: RED, trend with deploys, where failures concentrate,
  correlated error groups, slowest and failing traces. Raw attributes come
  last.
- **Laravel-shaped screens** — Dashboard, Requests, Jobs, Queues, Commands,
  Scheduled Tasks, Exceptions, Queries, Cache, Outgoing, Mail & Notifications,
  Hosts, Logs, System, Web Vitals, Analytics, plus a full trace waterfall.
- **Signal correlation** — a trace shows the host/runtime signals recorded
  *around* it (CPU, load, memory, network, RSS), each flagged against its
  typical baseline ("Host CPU 95%, typical 30%").
- **Infrastructure discovery** — finds the exporters already scraped into
  your Prometheus (node, redis, postgres, mysql, haproxy, nginx, php-fpm,
  elasticsearch) and ties each instance to the host or dependency your
  traces name. Nothing to configure; every metric name verified against
  the exporter's own output, and whatever could not be matched is printed
  rather than silently dropped.
- **Annotations** — deploy/incident/scaling/version markers on every chart,
  written through the telemetry pipeline (`telemetry-ui:annotate`) and
  auto-detected for un-announced deploys (`telemetry-ui:scan-versions`).
- **Issue trackers** — GitHub, Sentry and Linear as a fourth signal; create a
  ticket from an error group without leaving the drawer.
- **MCP server** — metrics, traces, logs and the correlation tools over the
  Model Context Protocol (`php artisan mcp:start telemetry-ui`, or HTTP with
  OAuth + Dynamic Client Registration) for agent-driven incident RCA.
- **Fleet-aware & autodetecting** — a service/environment switcher scopes every
  screen; optional schema families (e.g. `cboxdk/statamic-telemetry`) light up
  their own pages when their metrics exist.
- **Extensible in PHP** — add pages and panels with plain PHP classes that
  return a typed payload (`Ui::table()`, `Ui::stats()`, a chart), or declare a
  chart over any metric with `TelemetryUi::metricPanel()` — no class, which is
  how a sidecar in another language gets its own page. No JS build on your side.
- **Embeddable** — mount panels, Explore, entity pages and traces as React
  components inside your own React/Inertia app. They ship inside the composer
  package, so npm installs them from `vendor/`; no registry.
- **Fast to work in** — filter-bar value typeahead, ⌘K, keyboard triage (`j`/`k`
  through traces, `[`/`]` to step the time window), saved views, the compiled
  TraceQL/LogQL behind any view with copy-as-curl, and no dead ends: every
  number that names a subset opens it.
- **Inert when idle** — boot registers class-string maps only; disable with one
  env var.

## Install

```bash
composer require cboxdk/laravel-telemetry-ui
```

PHP 8.3+, Laravel 12 or 13. No Node toolchain — the UI ships prebuilt.

`cboxdk/laravel-telemetry` is a **hard dependency** — it defines the schema this
UI reads, and provides the write path for annotations (the dashboard also
instruments its own stack).

```dotenv
TELEMETRY_UI_METRICS_URL=http://prometheus:9090
TELEMETRY_UI_TEMPO_URL=http://tempo:3200
TELEMETRY_UI_LOKI_URL=http://loki:3100
```

Already run a Grafana Cloud / hosted LGTM stack? Point at the datasource proxy
instead — see [connect through a Grafana proxy](docs/cookbook/connect-via-grafana-proxy.md).

Then visit `/telemetry-ui`. The SPA and its assets are served by the package —
no publishing, no `npm` step. Access is gated by the `viewTelemetryUi` gate,
which allows only the `local` environment by default — open it up in your
app:

```php
Gate::define('viewTelemetryUi', fn ($user) => $user?->isAdmin() ?? false);
```

## Documentation

Full documentation lives in [`docs/`](docs/index.md):

- [Getting started](docs/getting-started/installation.md)
- [Connections](docs/core-concepts/connections.md) ·
  [Configuration reference](docs/core-concepts/configuration.md) ·
  [Signal correlation](docs/core-concepts/correlation.md) ·
  [Infrastructure discovery](docs/core-concepts/infrastructure.md)
- [Pages & panels](docs/core-concepts/pages-and-panels.md) ·
  [Dimensions & Explore](docs/core-concepts/dimensions-and-explore.md) ·
  [JSON API](docs/core-concepts/api.md)
- Cookbook: [Grafana proxy](docs/cookbook/connect-via-grafana-proxy.md) ·
  [annotations](docs/cookbook/annotations.md) · [MCP](docs/cookbook/mcp.md) ·
  [embed in your own app](docs/cookbook/embed-widgets.md)
- Extending: [developer integrations](docs/extension-points/index.md) (start here) ·
  [custom panels](docs/extension-points/custom-panels.md) ·
  [custom detail pages](docs/extension-points/detail-pages.md) ·
  [custom drivers](docs/extension-points/custom-drivers.md) ·
  [issue trackers](docs/extension-points/issue-trackers.md)
- [Design direction](docs/design/direction.md) · [ADRs](docs/adr/index.md) ·
  [Roadmap](docs/roadmap.md)

## Development

```bash
composer check   # pint + phpstan (level 8) + pest — must pass
npm install
npm test         # vitest (SPA unit tests)
npm run build    # typecheck + vite build into public/build (commit the result)
```

The SPA source lives in `resources/app/`. After any UI change, run
`npm run build` and commit `public/build` — hosts install the package without
Node, so the built assets are part of the release.

## License

MIT — see [LICENSE.md](LICENSE.md).
