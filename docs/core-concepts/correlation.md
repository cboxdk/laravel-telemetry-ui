---
title: Signal correlation
description: Host and runtime signals recorded around a trace, each vs. its typical baseline
weight: 4
---

# Signal correlation

An app-only monitor can tell you a request was slow. It can't tell you the box
was busted — that the host sat at 95% CPU while it ran, when it's usually 30%.
This UI can, because the same Prometheus that scrapes the app's spans also
scrapes system and process metrics (and `node_exporter`, `mysqld_exporter`, …
when you run them) right next to it. Open a trace and the drawer shows a strip
of context tiles: the host and runtime signals recorded *around* that trace,
each compared to what's typical for its scope.

The engine is headless — `Analysis\SignalContext` — so the trace drawer, the
comparison badges and the MCP `trace_context` tool all read the same summaries.

## What a trace shows

`SignalContext::forTrace()` derives the scope from the trace's root span: its
`service_name`, plus `host_name` if the root service resource carries a
`host.name`. It then queries each configured signal over a window padded around
the trace — `context.window` seconds total, split half before the first span
and half after the last — so surrounding metric samples land in view even for
a sub-second request.

Each signal comes back as a `MetricSummary`: `current` (last sample), `avg`,
`max`, the `points` behind a sparkline, and a `baseline`. The tile renders the
current value against that baseline:

```
Host CPU   95%  ⚠
           typ 30%
```

A signal is an **outlier** — the `⚠` flag and a red sparkline — when it's
materially above its usual level: `current >= baseline * 1.5` (the 50%-over
rule guards against tiny or zero baselines). That's the "what was different?"
answer, at a glance.

## Baselines

A baseline is the signal's *typical* value for this scope, computed as the
average over a long lookback. The lookback is `context.baseline_window` seconds
(default 6 hours) and it ends where the trace window begins — so "typical" is
the recent normal, never contaminated by the spike you're currently inspecting.

Baselines are multi-hour averages that barely move, so they're cached far
longer than the live query cache and, crucially, **shared across nearby
traces** rather than re-run per trace. The cache key coarsens the window start
to a 300-second bucket:

```php
$bucket = intdiv($end->getTimestamp(), 300) * 300;
$key = 'telemetry-ui:baseline:'.hash('xxh128', $query.'|'.$bucket);
```

Every trace whose window falls in the same 5-minute bucket hits the same key,
so opening one trace after another doesn't re-run the expensive lookback query.
Entries live for `context.baseline_ttl` seconds (default 120).

## The `{scope}` token

Each signal's `query` is a PromQL template with a `{scope}` token. It expands
to the scope's matcher list — the label/value pairs joined by commas, each
value quote-escaped:

```php
// scope ['service_name' => 'cbox-web', 'host_name' => 'web-1']
// {scope} -> service_name="cbox-web",host_name="web-1"
```

So `avg(system_cpu_utilization_ratio{{scope}})` becomes
`avg(system_cpu_utilization_ratio{service_name="cbox-web",host_name="web-1"})`.
The double braces are intentional: the outer pair is PromQL's label selector,
the inner `{scope}` is the token. When a scope is empty the expansion tidies
the stray commas an empty `{scope}` would leave, so `{{scope},state="used"}`
still yields valid PromQL.

## Configuration

The `context` block in `config/telemetry-ui.php`:

```php
'context' => [
    'enabled' => (bool) env('TELEMETRY_UI_CONTEXT', true),
    'window' => (int) env('TELEMETRY_UI_CONTEXT_WINDOW', 600),
    'baseline_window' => (int) env('TELEMETRY_UI_CONTEXT_BASELINE', 21_600),
    'baseline_ttl' => (int) env('TELEMETRY_UI_CONTEXT_BASELINE_TTL', 120),
    'signals' => [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
        ['label' => 'Load avg', 'group' => 'host', 'unit' => 'number', 'query' => 'max(system_cpu_load_average_ratio{{scope}})'],
        ['label' => 'Host memory', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_memory_utilization_ratio{{scope},state="used"})'],
        ['label' => 'Net in', 'group' => 'host', 'unit' => 'bytes/s', 'query' => 'sum(rate(system_network_io_bytes{{scope},direction="receive"}[1m]))'],
        ['label' => 'Process RSS', 'group' => 'runtime', 'unit' => 'bytes', 'query' => 'avg(process_resident_memory_bytes{{scope}})'],
    ],
],
```

| Key | Env | Meaning |
| --- | --- | --- |
| `enabled` | `TELEMETRY_UI_CONTEXT` | Master switch. When off, `SignalContext` returns no tiles. |
| `window` | `TELEMETRY_UI_CONTEXT_WINDOW` | Seconds padded *around* a trace (half each side, floored at 60). Default 600. |
| `baseline_window` | `TELEMETRY_UI_CONTEXT_BASELINE` | Lookback for the "typical" average, ending where the trace window starts (floored at 300). Default 21 600 (6 h). |
| `baseline_ttl` | `TELEMETRY_UI_CONTEXT_BASELINE_TTL` | Cache lifetime for a baseline, in seconds (floored at 30). Default 120. |
| `signals` | — | The tiles. Each has `label`, `group`, `unit` and a `{scope}` `query`. |

Per signal:

- **`label`** — the tile's name (`Host CPU`).
- **`group`** — one of `host`, `runtime`, `db`, `cache`, `custom`; anything
  else falls back to `custom`. Drives the tile's colour class.
- **`unit`** — how `current` and `baseline` render: `ratio` → percent,
  `bytes` / `bytes/s`, `ms`, or a plain trimmed number for anything else.
- **`query`** — the PromQL template. It must reduce to a single series (each
  built-in wraps its metric in `avg`/`max`/`sum`); the summary reads the first
  series' points.

The metric names above are the ones `cboxdk/laravel-telemetry` emits via OTLP.
If your exporters use different names, retarget the queries.

## Fail-open

Signals resolve independently and fail-open. If a signal's `queryRange` throws
a `SourceException` — a metric that doesn't exist because you don't run
`node_exporter`, a backend hiccup — that signal is silently skipped, never an
error on the drawer. A signal that returns no data, or all-zero data, is
likewise dropped rather than rendered as an empty tile. So the strip only ever
shows signals that actually have something to say, and adding a signal for an
exporter you don't run costs you nothing.

## Adding custom signals

Append to `signals`. Any single-series PromQL works, as long as it carries the
`{scope}` token wherever you want it filtered to the trace's service and host:

```php
['label' => 'Disk busy', 'group' => 'host', 'unit' => 'ratio',
 'query' => 'avg(rate(node_disk_io_time_seconds_total{{scope}}[1m]))'],
```

Not every exporter carries the app's labels, though. A `mysqld_exporter`
scraped on the same box labels its series with an `instance`, not a
`service_name`. To pull it in, join on the host — drop the `{scope}` token and
match the exporter's own label, mapping `host_name` to its `instance` for your
setup:

```php
['label' => 'DB threads', 'group' => 'db', 'unit' => 'number',
 'query' => 'mysql_global_status_threads_running{instance="web-1:9104"}'],
```

The signal gets the same baseline and outlier treatment as the built-ins, so a
trace that ran while MySQL was pinned on connections flags right alongside the
host CPU.

## Reuse

Because `SignalContext` is headless it isn't tied to the drawer. `forTrace()`
takes a `Trace` and returns `list<MetricSummary>`; `for(array $scope, $start,
$end)` takes an explicit scope and window for anything that isn't a single
trace. The MCP `trace_context` tool calls exactly the same `forTrace()`, so an
agent asking "what was the host doing when this trace ran?" gets the same
correlated signals — baselines, outlier flags and all — that an operator sees
in the drawer.
