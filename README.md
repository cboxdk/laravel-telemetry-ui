# Laravel Telemetry UI

**Grafana-replacement observability UI for Laravel** — queries your existing
Tempo (traces), Loki (logs) and Prometheus/Mimir (metrics) directly. No
agent, no vendor cloud, no data leaves your infrastructure. The presentation
counterpart to
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry),
schema-aware of every metric and span attribute it emits.

> **Status: alpha.** The screens and connectors are in daily use, but APIs may
> still shift before 1.0. Pin a version and read the
> [CHANGELOG](CHANGELOG.md) before upgrading.

## Why not just Grafana?

Grafana is generic; this dashboard knows what a Laravel app *is*. Routes,
jobs, scheduled tasks, queries, cache stores and users are first-class
concepts, cross-linked across signals — click a slow route, see its traces;
open a trace, see the queries, the trace-correlated logs, **and the host it ran
on**. And it lives inside your app: your auth, your Livewire stack, and actions
a read-only dashboard can't do (open a ticket from an exception, talk to it
over MCP).

## Highlights

- **Laravel-shaped screens** — Dashboard, Requests, Jobs, Commands, Scheduled
  Tasks, Exceptions, Queries, Cache, Outgoing, Mail & Notifications, Users,
  Hosts, Logs, System, plus full trace search + waterfall.
- **Purpose-built drill-down** — clicking a route/job/exception/host opens a
  dedicated detail page scoped to that entity (throughput → latency → exact
  status codes → its individual traces), not a generic filtered search.
- **Dimensional filtering** — every attribute in a trace (host, user, team,
  client IP, deployment…) is a click-to-filter link, Grafana-style. A **Hosts**
  page lists every server reporting telemetry.
- **Signal correlation** — a trace shows the host/runtime signals recorded
  *around* it (CPU, load, memory, network, RSS), each flagged against its
  typical baseline ("Host CPU 95%, typical 30%"). The thing an app-only
  monitor can't do — same Prometheus, right next to the app.
- **Annotations** — deploy/incident/scaling/version markers as vertical lines
  on every chart, written through the telemetry pipeline
  (`telemetry-ui:annotate`) and auto-detected for un-announced deploys
  (`telemetry-ui:scan-versions`).
- **Issue trackers** — GitHub, Sentry and Linear as a fourth signal; create a
  ticket from an exception without leaving the drawer.
- **MCP server** — serve metrics, traces, logs and the correlation tools over
  the Model Context Protocol (`php artisan mcp:start telemetry-ui`, or HTTP with
  OAuth + Dynamic Client Registration) so an agent can query your stack for
  incident RCA.
- **Fleet-aware & autodetecting** — a service/environment switcher scopes every
  screen; optional schema families (e.g. `cboxdk/statamic-telemetry`) light up
  their own pages when their metrics exist.
- **Extensible in PHP** — add pages and cards with Blade + any
  PromQL/TraceQL/LogQL. No JS build.
- **Inert when idle** — boot registers class-string maps only; disable with one
  env var.

## Install

```bash
composer require cboxdk/laravel-telemetry-ui
```

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

Then visit `/telemetry-ui`. Access is gated by the `viewTelemetryUi` gate,
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
  [Signal correlation](docs/core-concepts/correlation.md)
- [Pages & cards](docs/core-concepts/pages-and-cards.md)
- Cookbook: [Grafana proxy](docs/cookbook/connect-via-grafana-proxy.md) ·
  [annotations](docs/cookbook/annotations.md) · [MCP](docs/cookbook/mcp.md)
- Extending: [custom cards](docs/extension-points/custom-cards.md) ·
  [custom detail pages](docs/extension-points/detail-pages.md) ·
  [custom drivers](docs/extension-points/custom-drivers.md) ·
  [issue trackers](docs/extension-points/issue-trackers.md)
- [Design direction](docs/design/direction.md) · [ADRs](docs/adr/) ·
  [Roadmap](docs/roadmap.md)

## Development

```bash
composer check   # pint + phpstan (level 8) + pest — must pass
npm run build    # rebuild the ECharts bundle in public/
```

## License

MIT — see [LICENSE.md](LICENSE.md).
