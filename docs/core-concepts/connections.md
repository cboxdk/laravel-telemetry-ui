---
title: Connections
description: Named backend connections and the three source contracts
weight: 1
---

# Connections

The UI never talks to a backend directly — cards depend on three narrow
contracts, resolved through named connections in `config/telemetry-ui.php`:

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
        'headers' => [],           // e.g. Authorization
        'timeout' => 10.0,
    ],
    'traces' => ['driver' => 'tempo', 'url' => env('TELEMETRY_UI_TEMPO_URL')],
    'logs' => ['driver' => 'loki', 'url' => env('TELEMETRY_UI_LOKI_URL')],
],
```

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
  upstream message; cards render it as an inline error state.

## Result types

Drivers return plain readonly DTOs, so cards never touch raw JSON:

- `Sample` (instant vector element) and `TimeSeries`/`DataPoint` (range) with
  an ECharts-ready `toChartData()`.
- `TraceSummary` (search hit) and `Trace`/`Span` (full OTLP trace with typed
  attributes, `SpanKind`, error status and parent/child helpers).
- `LogEntry` (line + stream labels + nanosecond timestamp).
