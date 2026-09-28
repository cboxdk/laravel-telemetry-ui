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
(Mimir's query-frontend splitting and results cache, recording rules for
the per-route histograms).

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
  Loki alike.
- **Mimir = Prometheus + prefix** — the `mimir` driver is the Prometheus
  driver with a default `/prometheus` path prefix and tenancy.
- **Errors** — all drivers throw `SourceException` with the failing URL and
  upstream message; a panel returns it as an inline error state and an API
  endpoint as a typed `backend` error (502).

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
