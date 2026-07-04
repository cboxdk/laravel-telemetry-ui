---
title: Laravel Telemetry UI
description: Grafana-replacement observability UI for Laravel apps instrumented with cboxdk/laravel-telemetry
weight: 1
---

# Laravel Telemetry UI

`cboxdk/laravel-telemetry-ui` is a Laravel-native observability dashboard that
queries your existing Grafana stack — **Tempo** (traces), **Loki** (logs) and
**Prometheus/Mimir** (metrics) — directly. No agent, no vendor cloud, no data
leaves your infrastructure.

It is the presentation counterpart to
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry): the
emitting package defines a stable schema of metric names, span attributes and
resource attributes, and this package is *schema-aware*. That is what lets it
render opinionated, Laravel-shaped screens (Requests, Jobs, Queries,
Exceptions, Users…) instead of Grafana's generic panels — and link them:
click a slow route, see its traces; open a trace, see the queries and the
trace-correlated logs.

```php
// Everything on the dashboard is a Livewire "card". Your packages add theirs:
TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity');
TelemetryUi::card(AutoscaleDecisions::class, page: 'autoscale');

// A card is PHP + Blade + any PromQL/TraceQL/LogQL you like:
final class AutoscaleDecisions extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = $this->metrics()->queryRange(
            'sum by (queue) (rate(autoscale_scaling_events_total[5m]))',
            $start, $end,
        );

        // ...
    }
}
```

## Why not just Grafana?

Grafana is generic; this dashboard knows what a Laravel app *is*. Routes,
jobs, scheduled tasks, cache stores and users are first-class concepts, cross
linked across signals. It also lives inside your app: your auth, your
Livewire stack, and actions a read-only dashboard can never do — create a
ticket from an exception, or hand the whole stack to an agent over MCP for
incident RCA.

## Documentation

- [Getting started](getting-started/installation.md)
- Core concepts:
  [connections](core-concepts/connections.md) ·
  [pages & cards](core-concepts/pages-and-cards.md) ·
  [configuration reference](core-concepts/configuration.md) ·
  [signal correlation](core-concepts/correlation.md)
- Cookbook:
  [web analytics & RUM](cookbook/analytics.md) ·
  [connect through a Grafana datasource proxy](cookbook/connect-via-grafana-proxy.md) ·
  [emitting annotations](cookbook/annotations.md) ·
  [MCP server](cookbook/mcp.md)
- Extension points:
  [custom cards](extension-points/custom-cards.md) ·
  [custom detail pages](extension-points/detail-pages.md) ·
  [custom drivers](extension-points/custom-drivers.md) ·
  [issue trackers](extension-points/issue-trackers.md)
- [Design direction](design/direction.md)
- [Architecture decision records](adr/)
- [Roadmap](roadmap.md)
