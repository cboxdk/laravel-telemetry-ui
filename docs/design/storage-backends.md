---
title: Storage backends & the query abstraction
description: A pluggable query IR that lets cards run against Prometheus/Tempo/Loki today and native ClickHouse (cboxdk/laravel-telemetry-store) next
weight: 2
---

# Storage backends & the query abstraction

Today every card speaks the LGTM stack's own dialects — cards build PromQL,
TraceQL and LogQL *strings* and hand them to a driver (see
[direction.md](direction.md) for the screen → query mapping). That hard-wires
the read side to Grafana's query languages. This document plans the move to a
**backend-agnostic query layer** so the same cards can also run against a
native **ClickHouse** OTEL store — with the door left open for further
backends (a relational/Eloquent store) later.

## Goals & non-goals

- **Goal:** one card, many backends. A card describes *what* it wants; each
  driver compiles that to *its* dialect (PromQL / TraceQL / LogQL today, SQL
  next).
- **Goal:** ClickHouse as an *additional* connection driver, selectable
  per-connection (`metrics`/`traces`/`logs` can each point at ClickHouse or the
  LGTM stack independently). No forced migration off LGTM.
- **Goal:** a native PHP OTLP ingest path into ClickHouse — no OpenTelemetry
  Collector in the loop.
- **Non-goal (now):** the Eloquent/relational backend. The abstraction is
  designed so it can slot in later, but we build only ClickHouse.
- **Non-goal:** a general-purpose PromQL engine. The IR covers the *bounded
  subset* of query shapes the cards actually emit — nothing more.

## Decisions locked

- **Clean break, not additive.** The three `*Source` contracts change their
  input from dialect strings to query objects outright. Single user of the
  package today, so no dual-method deprecation window. Ships as **v0.4**
  (contract-breaking).
- **Output DTOs stay.** `Sample`, `TimeSeries`, `DataPoint`, `TraceSummary`,
  `Trace`, `Span`, `LogEntry` are already backend-agnostic and are **not
  touched**. Only the *input* side (string → query object) changes. This bounds
  the blast radius.
- **New package** `cboxdk/laravel-telemetry-store` holds the ClickHouse-specific
  code: native OTLP ingest, the ClickHouse schema, and the read drivers
  (registered into this UI via `TelemetryUi::extend()`). It depends on
  `laravel-telemetry-ui` for the contracts/IR/DTOs.

## The three layers

```
Lag 1 — Query IR            (in laravel-telemetry-ui)
  Card ──build──▶ MetricQuery / TraceQuery / LogQuery
                        │
                        ├─▶ PromQL / TraceQL / LogQL   (existing Prometheus/Tempo/Loki drivers)
                        └─▶ ClickHouse SQL             (new -store drivers)

Lag 2 — Native OTLP ingest  (in laravel-telemetry-store)
  emitter ──OTLP/HTTP JSON──▶ /v1/{logs,traces,metrics} ──▶ StoreWriter ──▶ ClickHouse

Lag 3 — Read drivers        (in laravel-telemetry-store)
  ClickHouse{Metrics,Traces,Logs}Source implement the Lag-1 contracts,
  querying exactly the schema Lag 2 wrote.
```

## Lag 1 — the query IR

The core enabler, and the biggest risk. It replaces string-building in
`Cards/Concerns/ScopesQueries` and the `metric()`/`traceScope()`/`logSelector()`
helpers with typed value objects that the drivers compile.

### What the IR must express

Derived from what cards actually emit today (see `direction.md` mapping):

- **MetricQuery**
  - metric name
  - matchers: `list<{label, op: `=`|`!=`|`=~`|`!~`, value}>` (scope + entity)
  - function: `raw` | `rate(window)` | `increase(window)` | `histogram_quantile(q, window)`
  - aggregation: `sum`|`avg`|`min`|`max`|`count` + `by` group labels
  - optional `topk(n)`
  - scalar post-op (`* 60`): **kept in the card**, not the IR
- **LogQuery**: stream matchers · line filters (`contains`/`regex`) · limit ·
  direction · time range → a trivial SQL `WHERE`.
- **TraceQuery**: attribute matchers · duration filter · limit · time range ·
  plus fetch-by-id.

### Key simplification: derived labels live in the card, not the IR

`RequestsActivity` today pushes a `label_replace(..., "class", "${1}xx",
"http_response_status_code", "([0-9])..")` into PromQL *and then re-buckets the
result in PHP* (`bucketedSeries()`/`bucket()`). The regex-capture-into-a-label
is exactly the kind of dialect-specific power an IR should **not** try to model.

Rule: **the IR groups by raw labels only; cards derive/bucket labels in PHP.**
So `RequestsActivity` becomes: `MetricQuery(http_..._count).rate(window).sum(by:
['http_response_status_code'])`, and the existing PHP bucketing collapses status
codes into ok/4xx/5xx. Several cards already do their bucketing in PHP, so this
mostly *removes* PromQL, it doesn't add PHP.

For the rare card that genuinely needs a backend-specific expression, the IR
carries a typed **escape hatch** (a raw-expression node a driver may reject with
`UnsupportedQuery` if it can't compile it) — used sparingly, and never for the
common shapes above.

### Contract changes

```
MetricsSource::query(MetricQuery $q, ?DateTimeInterface $at = null): list<Sample>
MetricsSource::queryRange(MetricQuery $q, DateTimeInterface $start, $end, ?int $step): list<TimeSeries>
MetricsSource::labelValues(...)                          // unchanged shape

TracesSource::search(TraceQuery $q, $start, $end, int $limit): list<TraceSummary>
TracesSource::trace(string $traceId): Trace              // unchanged
TracesSource::tagValues(...)                             // unchanged

LogsSource::query(LogQuery $q, $start, $end, int $limit): list<LogEntry>
LogsSource::labelValues(...)                             // unchanged
```

Each existing driver gains a small **compiler**: `PromqlCompiler`,
`TraceqlCompiler`, `LogqlCompiler` turning the IR back into today's strings.
Because the output is byte-for-byte the current queries, the existing
`Http::fake` feature tests are the regression net — they should pass unchanged
once the compilers reproduce the strings.

`ScopesQueries` stops returning strings and instead returns IR fragments
(scoped matcher lists), so the scope-lock / fail-closed semantics move into the
IR builder untouched in behaviour.

### Migration path (kept green throughout)

1. Add the IR value objects + the three compilers as **new** code; no contract
   change yet. Unit-test each compiler against the current card strings.
2. Flip `ScopesQueries` to build IR; flip the contracts; update the LGTM drivers
   to accept IR and run it through their compiler. Migrate cards page-group by
   page-group. `composer check` green after each group.
3. Delete the string helpers once no card references them.

## Lag 2 — native OTLP ingest (`-store`)

The emitter (`cboxdk/laravel-telemetry`) already POSTs spec OTLP/HTTP JSON to
`/v1/traces`, `/v1/metrics`, `/v1/logs`. The `-store` package exposes those
routes and writes to ClickHouse directly — no Collector.

- **Routes/controllers** parse OTLP/HTTP JSON (the emitter's exact wire format;
  reuse its serializer knowledge) into row batches.
- **`StoreWriter` abstraction** with a `ClickHouseWriter` implementation (bulk
  async inserts over ClickHouse HTTP). The abstraction is the seam a future
  relational writer plugs into.
- **Schema** modelled on the OTel Collector's ClickHouse exporter tables so it's
  familiar and tool-compatible: `otel_traces`, `otel_logs`,
  `otel_metrics_sum` / `_gauge` / `_histogram`. DDL shipped as versioned
  migrations the package can run against ClickHouse.
- Batching/backpressure and retention (TTL on the ClickHouse tables) are the
  package's concern, not the app's.

## Lag 3 — ClickHouse read drivers (`-store`)

`ClickHouseMetricsSource` / `ClickHouseTracesSource` / `ClickHouseLogsSource`
implement the Lag-1 contracts and compile the IR to SQL over the Lag-2 schema.
Registered via `TelemetryUi::extend('clickhouse-metrics', …)` so this UI's
built-in driver list (LGTM) is unchanged and ClickHouse is opt-in per
connection:

```php
'metrics' => ['driver' => 'clickhouse-metrics', 'url' => '…', 'database' => 'telemetry', …]
```

The read driver and the write schema ship together (they must agree), which is
why both live in `-store`.

## Difficulty ranking → build order

Signals are wildly different in SQL difficulty, so we roll out signal by signal:

1. **Lag 1 refactor** — no new behaviour; pure IR + compilers with the existing
   test net. Foundation for everything.
2. **Logs** — rows → trivial `WHERE`/`LIKE`/regex. Cheapest full-stack proof
   (ingest + schema + read driver end to end).
3. **Traces** — rows + span-tree reconstruction. Still natural in SQL.
4. **Metrics last** — `rate()` / `histogram_quantile()` over **cumulative**
   series in SQL is the hard part: per-series ordering, counter-reset handling,
   `le`-bucket quantiles. ClickHouse is the real target here (window functions);
   this is where most of the SQL-compiler effort goes.

## Open questions

- Package split: keep ingest + read drivers in one `-store` package, or split
  `-store` (ingest + schema, UI-free) from a thin UI-driver package later? Start
  as one; revisit if ingest wants to be used without the UI.
- ClickHouse client: HTTP interface (simple, no ext) vs. a native client
  package. Lean HTTP first.
- Metrics representation: store raw cumulative OTLP data points and compute
  rate/quantile at read time (flexible, heavier reads) vs. pre-aggregate on
  ingest (cheaper reads, lossy). Start read-time; measure.

## Status — implemented

Lag 1 shipped signal by signal, each a clean contract break, all green under
`composer check` (pint, phpstan level 8, the full test suite):

- **Logs** — `LogQuery` + `LogqlCompiler`; `LogsSource` takes the IR.
- **Traces** — `TraceQuery`/`TraceCondition` + `TraceqlCompiler`; the raw `?q=`
  box and hand-built queries go through `TraceQuery::raw()` (scope still enforced
  as a string first).
- **Metrics** — `MetricQuery` (fluent `rate`/`increase`/`counterIncrease`/
  `quantile`/`sumBy`/…) + `PromqlCompiler`. `label_replace(class)` was dropped in
  favour of grouping on the raw status code and bucketing in PHP.

The IR deliberately doesn't model everything. Genuine escape-hatch cases use
`MetricQuery::raw()`: nested double-aggregation (system memory/filesystem),
arithmetic between two metrics (request avg latency), and config-driven exporter
queries (system/host cards, MCP tools, schema detection). These are PromQL-only
and a SQL backend rejects them — acceptable, since they're operator-supplied
exporter queries, not core cards.

The IR carries **snake_case, Loki/Prometheus-style** label names throughout
(that's what the cards read), so the ClickHouse driver bridges them to dotted
OTLP keys + promoted columns on the way in and out.

The ClickHouse side lives in **`cboxdk/laravel-telemetry-store`** (sibling repo):
native OTLP ingest → `otel_*` tables, and `ClickHouse{Logs,Traces,Metrics}Source`
registered via `TelemetryUi::extend('clickhouse-{logs,traces,metrics}')`. Its own
`composer check` is green (parser, compilers, quantile math and driver behaviour
unit/feature-tested against a faked ClickHouse).

**Still needs a live ClickHouse to validate** (flagged in-code): metric-name
reconciliation (Prometheus `_bucket`/`_count`/`_sum` + unit suffixes ↔ stored
OTLP names), cumulative-counter delta precision (reset handling), and the
histogram-quantile approximation. This is exactly the "metrics last / hardest in
SQL" area called out above.
