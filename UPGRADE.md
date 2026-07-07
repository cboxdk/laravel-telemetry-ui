# Upgrade guide

## 0.x → 1.0

The only breaking change is the query layer: the three source contracts now take
**typed query objects** instead of dialect strings. Result DTOs (`Sample`,
`TimeSeries`, `Trace`, `Span`, `LogEntry`, …) are unchanged.

### If you only use the built-in cards / LGTM drivers

Nothing to do. The bundled Prometheus/Mimir/Tempo/Loki drivers compile the new
IR to the exact same PromQL/TraceQL/LogQL as before — your existing backends and
data keep working unchanged.

### If you wrote a custom driver

Update the method signatures to accept the IR and compile it to your dialect.

```php
use Cbox\TelemetryUi\Queries\Ir\{MetricQuery, TraceQuery, LogQuery};
use Cbox\TelemetryUi\Queries\Compilers\{PromqlCompiler, TraceqlCompiler, LogqlCompiler};

// before: public function query(string $promql, ?DateTimeInterface $at = null): array
public function query(MetricQuery $query, ?DateTimeInterface $at = null): array
{
    $promql = (new PromqlCompiler)->compile($query); // if your backend speaks PromQL
    // ...or read $query->name / ->matchers / ->fn / ->agg / ->by / ->quantile
    //    directly and build your own dialect (see the ClickHouse store driver).
}
```

The affected signatures:

| Contract | Before | After |
| --- | --- | --- |
| `MetricsSource::query` | `string $promql` | `MetricQuery $query` |
| `MetricsSource::queryRange` | `string $promql` | `MetricQuery $query` |
| `TracesSource::search` | `string $traceql` | `TraceQuery $query` |
| `TracesSource::tagValues` | `?string $traceql` | `?TraceQuery $filter` |
| `LogsSource::query` | `string $logql` | `LogQuery $query` |

`MetricsSource::labelValues`, `TracesSource::trace`, `LogsSource::labelValues`
are unchanged.

### If you wrote a custom card

Replace inline query strings with the IR builders on the base card:

```php
// before
$this->metrics()->query('sum by (queue) (increase('.$this->metric('jobs_total').'[1h]))');
// after
$this->metrics()->query($this->metric('jobs_total')->increase('1h')->sumBy('queue'));
```

`metric()` now returns a `MetricQuery`; `logSelector()` a `LogQuery`;
`traceScope()` stays a string but `traceQuery(...)` gives you a `TraceQuery`.
For a hand-written dialect string that doesn't fit the builders, wrap it with
`MetricQuery::raw()` / `TraceQuery::raw()` / `LogQuery::raw()`.
