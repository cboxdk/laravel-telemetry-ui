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

Issue trackers are already a fourth signal on the same pattern: implement
[`IssuesSource`](issue-trackers.md) (GitHub, Sentry and Linear ship built in)
and, optionally, `CreatesIssues` — no changes to the card model.
