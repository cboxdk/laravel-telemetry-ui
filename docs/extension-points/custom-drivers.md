---
title: Custom drivers
description: Teach the connection manager new backends
weight: 2
---

# Custom drivers

The built-in drivers are `prometheus`, `mimir`, `tempo` and `loki`. Register
another backend by implementing the relevant contract and extending the
manager:

```php
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\MetricsSource;

public function boot(): void
{
    $this->callAfterResolving(ConnectionManager::class, function (ConnectionManager $manager): void {
        $manager->extend('victoriametrics', fn (array $config): MetricsSource => new VictoriaMetricsSource(
            $manager->client($config),
        ));
    });
}
```

```php
'connections' => [
    'metrics' => ['driver' => 'victoriametrics', 'url' => 'http://vm:8428'],
],
```

`callAfterResolving` keeps boot lazy: the creator closure is registered only
if/when the manager is first used.

**Reuse `$manager->client($config)`** rather than constructing `ApiClient`
yourself — it applies the connection's Bearer/basic auth, `X-Scope-OrgID`
tenancy, timeout, query cache and retries from config. Building `ApiClient`
by hand silently skips all of that.

## Optional capabilities

A driver can declare extra abilities alongside its signal contract; callers
feature-detect them and degrade when they are absent, so adding one never
breaks a driver that does not have it.

- **`Contracts\EnumeratesMetricNames`** — `metricNamesMatching(array $patterns,
  string $scope = '')` returns the metric names present that match any of the
  patterns. [Page detection](../core-concepts/pages-and-cards.md#autodetected-pages)
  asks about every registered pattern on every request; a driver with this
  capability answers all of them in one call instead of one `count()` query per
  pattern. Prometheus/Mimir resolve it from the series index. Return only names
  whose series an instant query would still see, or a family that fell silent
  keeps its page alive.
- **`Contracts\ProbesConnection`** — `probe()` answers "is this connection
  usable?" without running a dashboard query.
- **`Contracts\AggregatesSpans`** — server-side span aggregation for a traces
  driver that can do it exactly (a ClickHouse store can; Tempo cannot).

Issue trackers are already a fourth signal on the same pattern: implement
[`IssuesSource`](issue-trackers.md) (GitHub, Sentry and Linear ship built in)
and, optionally, `CreatesIssues` — no changes to the card model.
