---
title: Connections
description: Named backend connections and the three source contracts
weight: 21
---

# Connections

The UI never talks to a backend directly — panels and the API depend on three
narrow contracts, resolved through named connections in `config/telemetry-ui.php`:

| Contract | Query language | Built-in drivers |
| --- | --- | --- |
| `MetricsSource` | PromQL | `prometheus`, `mimir` |
| `TracesSource` | TraceQL | `tempo` |
| `LogsSource` | LogQL | `loki` |

```php
'connections' => [
    'metrics' => [
        'driver' => 'mimir',
        'url' => env('TELEMETRY_UI_METRICS_URL'),
        'prefix' => null,          // defaults to "prometheus" for mimir
        'tenant' => 'team-apps',   // sent as X-Scope-OrgID
        'token' => env('TELEMETRY_UI_METRICS_TOKEN'),      // -> Authorization: Bearer
        'basic_auth' => null,      // "user:pass" -> Authorization: Basic
        'headers' => [],           // any other headers
        'timeout' => 10.0,
    ],
    'traces' => ['driver' => 'tempo', 'url' => env('TELEMETRY_UI_TEMPO_URL')],
    'logs' => ['driver' => 'loki', 'url' => env('TELEMETRY_UI_LOKI_URL')],
],
```

## Long windows on Mimir

A panel's period figures — the requests in the window, the p95 across it,
per route — are window-wide reads: `increase(x[24h])`,
`histogram_quantile(0.95, …rate(bucket[24h]))`. Mimir's query-frontend
splits a *range* query by day (and caches each day), but an *instant* query
fetches every chunk of the whole window at once, so on a busy service the
24-hour p95 across every route is the first thing over
`max_fetched_chunks_per_query` — a 422 *the query exceeded the maximum number
of chunks* — while the same data draws fine as the panel's chart. A longer
window grows the instant query with it.

So the `mimir` driver reads a window longer than six hours as a range query
of slices (an hour each, up to a day each for long windows — a year is 365
of them) and adds them up: counters summed, a rate as the summed increase
over the window, a quantile by summing each bucket's increase and
interpolating the way `histogram_quantile` does. Each part the frontend runs
is at most a day, whatever the window, which is what makes 30 days or a year
answerable at all. The figures agree with the instant query to within
`increase()`'s edge extrapolation.

```php
'metrics' => [
    'driver' => 'mimir',
    // ...
    'split_after' => null,  // seconds; null = the driver's default (6h for
                            // mimir, off for prometheus); 0 = never
    'split_slice' => 3600,  // the preferred slice, in seconds
],
```

Plain Prometheus is left alone: it evaluates an instant `rate()` one series
at a time, and a range query of slices costs it more of
`--query.max-samples`, not less. If a `prometheus`-driver URL points at
Mimir, set the driver to `mimir` — or `TELEMETRY_UI_METRICS_SPLIT_AFTER=21600`.

Retention still bounds what a window shows: a 90-day dashboard over a
backend that keeps 15 days is a 15-day dashboard.

## When a backend refuses a query

A backend that refuses a query — Prometheus over `--query.max-samples`, a
Mimir per-query limit — answers with a status and a reason, and the panel
shows both ("refused the query (status 422): query processing would load too
many samples into memory"), so the fix is visible: raise the limit, or give
the backend recording rules for the long windows you use. Any other error
body (an HTML page from a proxy) stays out of the dashboard.

## Timeouts on busy backends

`timeout` is seconds per backend request (`TELEMETRY_UI_METRICS_TIMEOUT`,
`…_TEMPO_TIMEOUT`, `…_LOKI_TIMEOUT`; 10 by default). A long window over a
busy backend — a 24-hour p95 across every route of a service taking millions
of requests a day — can take longer than that, and a panel then says the
backend *did not answer within 10s*, which is different from *could not
reach* it.

A panel keeps what did answer: the chart and counts stay, the one read that
ran out of time shows a dash, and a note under the panel says what is
missing. Once one read has timed out the panel stops asking, so a struggling
backend is not made to wait out the timeout several times over. If that
happens on the windows you use, raise the timeout — keeping it under PHP's
`max_execution_time` — or give the backend what it needs for the window
(recording rules for the per-route histograms).

## Authentication

Backends behind an authenticated gateway take a Bearer token or basic auth:

- `token` → `Authorization: Bearer <token>` (e.g. a Grafana service account).
  Env: `TELEMETRY_UI_<CONN>_TOKEN`, or a shared `TELEMETRY_UI_TOKEN`.
- `basic_auth` → `Authorization: Basic <base64>`; give it as `user:pass`.
  Env: `TELEMETRY_UI_<CONN>_BASIC_AUTH`.
- An explicit `Authorization` under `headers` always wins.

To query Tempo/Loki/Mimir through Grafana instead of hitting them directly,
see [Connect through a Grafana datasource proxy](../cookbook/connect-via-grafana-proxy.md).

The keys `metrics`, `traces` and `logs` are the defaults. Additional named
connections are allowed and requested explicitly:

```php
'connections' => [
    // ...
    'metrics-eu' => ['driver' => 'prometheus', 'url' => 'http://prom-eu:9090'],
],
```

```php
$this->metrics('metrics-eu')->queryRange(...);
```

## Semantics

- **Lazy** — connections resolve on first use, never at boot.
- **Tenancy** — `tenant` sets `X-Scope-OrgID`, honoured by Mimir, Tempo and
  Loki alike. For a connection per viewer, see
  [Per-tenant connections](#per-tenant-connections).
- **Mimir = Prometheus + prefix** — the `mimir` driver is the Prometheus
  driver with a default `/prometheus` path prefix and tenancy.
- **Errors** — all drivers throw `SourceException` with the failing URL and
  upstream message; a panel returns it as an inline error state and an API
  endpoint as a typed `backend` error (502).

## Per-tenant connections

On a host that serves many tenants, resolve the connections per viewer with
`TelemetryUi::resolveConnectionsUsing()` (see
[Authorization](authorization.md#per-tenant-backends) for the resolver itself).
Three things keep tenants apart.

**Strict resolution.** By default a connection the resolver omits falls back
to the static config above. Register the resolver with `strict: true` and it
is refused instead: so is every connection for a request without a signed-in
viewer. Reads then fail with a `SourceException` (status `unauthorized`),
which a panel renders as its error state and the API as a typed `backend`
error. The static config is never read on a viewer's behalf.

```php
TelemetryUi::resolveConnectionsUsing(fn ($user) => $user->tenant->telemetryConnections(), strict: true);
```

**Caches are namespaced per tenant.** Everything cached from a backend's
answer (the service list, deploy markers, page detection, stream labels, the
infrastructure map, signal baselines, dimension display names and the HTTP
query cache) is keyed under `ConnectionManager::fingerprint()`. The
fingerprint is a hash of the connections in force: driver, url, tenant and the
other settings, with the token, basic auth and headers reduced to a hash. Two
viewers share cached answers only when they read the same backends with the
same credentials. The cache store is shared by every worker, so this holds
across requests and processes, not only within one. A custom driver or panel
that caches a backend's answer should key it the same way:

```php
$key = app(ConnectionManager::class)->cacheKey('my-panel:'.$query); // telemetry-ui:<fingerprint>:my-panel:…
```

**Headless runs.** A scheduled command or a queued job has no viewer to
resolve connections from. Run it inside `ConnectionManager::using()`, with
the same map a resolver returns:

```php
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Discovery\Discoverer;

$connections = app(ConnectionManager::class);

foreach (Tenant::all() as $tenant) {
    $connections->using($tenant->telemetryConnections(), fn () => app(Discoverer::class)->discover());
}
```

Inside the callback the map is the resolver's answer (the resolver itself is
not called): a connection it omits falls back to static config, or is refused
under a strict resolver. Calls nest, and the previous connections are back
when the callback returns or throws.

## Verifying a connection

Before trusting the dashboard, confirm the config actually reaches every
backend — a URL typo, a wrong token or a missing tenant otherwise only shows
up as an empty or broken panel:

```bash
php artisan telemetry-ui:check
```

Each configured connection is probed with its cheapest read (an instant
`vector(1)` for metrics, a `service.name` tag lookup for traces, a label
lookup for logs, a one-issue page for the tracker) and reported as
`OK` / `FAIL` / `not configured`. It exits non-zero if any probe fails, so it
doubles as a deploy healthcheck. Scope it with `--connection=metrics` (repeatable).

## Result types

Drivers return plain readonly DTOs, so panels never touch raw JSON:

- `Sample` (instant vector element) and `TimeSeries`/`DataPoint` (range) with
  an ECharts-ready `toChartData()`.
- `TraceSummary` (search hit) and `Trace`/`Span` (full OTLP trace with typed
  attributes, `SpanKind`, error status and parent/child helpers).
- `LogEntry` (line + stream labels + nanosecond timestamp).
