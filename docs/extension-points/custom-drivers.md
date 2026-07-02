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
            new ApiClient($config['url'], $config['headers'] ?? []),
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

Planned contracts follow the same pattern — an `IssuesSource` for
Sentry/Linear/GitHub is on the [roadmap](../roadmap.md) and will slot in as a
fourth signal without touching the card model.
