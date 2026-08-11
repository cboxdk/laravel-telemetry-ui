---
title: Pages & cards
description: The Livewire card model that both built-in screens and your packages use
weight: 2
---

# Pages & cards

Everything visible in the dashboard is a **card**: a Livewire component
extending `Cbox\TelemetryUi\Cards\Card`. Cards live on **pages**, which form
the sidebar (grouped Nightwatch-style: Activity, Monitoring, …).

There is deliberately no difference between a built-in card and a
third-party card — the Requests screen is built from the same primitives a
queue-autoscale package would use.

## Registering

Config-declared dashboard cards:

```php
// config/telemetry-ui.php
'cards' => [
    RequestsActivity::class,
],
```

Runtime registration from any service provider:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity');
    TelemetryUi::card(AutoscaleDecisions::class, page: 'autoscale');
}
```

Both calls are data-only (arrays of class-strings), so registering costs
nothing at boot. Livewire aliases are registered once, after all providers
have booted.

## Autodetected pages

Pages registered with a `detectMetric` pattern only appear when the metrics
backend actually contains matching metric names:

```php
TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity', detectMetric: 'autoscale_.*');
```

Detection is cached per pattern (TTL `telemetry-ui.detection.ttl`, default
300s). Undetected pages are hidden from the sidebar and 404. If the backend is
unreachable, detection fails open — the page stays visible and its cards render
their own error states.

Every registered pattern is resolved in **one** backend call per request: the
driver returns the metric names matching any of them and each pattern is
decided against that list here. On Prometheus/Mimir that is a single
`/api/v1/label/__name__/values` with a `match[]` selector, looking back the
same 5 minutes an instant query would. A driver that does not implement
[`EnumeratesMetricNames`](../extension-points/custom-drivers.md) falls back to
one `count({__name__=~"autoscale_.*"})` query per pattern — correct, but one
network round trip per detectable page, which is what the batched path exists
to avoid.

The built-in **Statamic** page works this way: install
[`cboxdk/statamic-telemetry`](https://github.com/cboxdk/statamic-telemetry)
in any monitored app and its `statamic_*` metrics (static cache, Stache,
Glide, forms, content changes) light the page up automatically — the
dashboard app itself does not need to be a Statamic app.

## What a card gets for free

```php
final class AutoscaleDecisions extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();      // from the global period selector

        $series = $this->metrics()->queryRange(...);   // MetricsSource
        $traces = $this->traces()->search(...);        // TracesSource
        $logs = $this->logs()->query(...);             // LogsSource

        return view('autoscale::cards.decisions', [
            'series' => $this->toChartSeries($series, label: 'queue'),
        ]);
    }
}
```

- `$this->period` is synced with the `?period=` query string and updated live
  by the `telemetry-ui:period-changed` Livewire event when the user switches
  the global 15M/1H/24H/7D/14D/30D selector.
- Because cards are Livewire components, interactivity (filters, drill-downs,
  actions like "create ticket", streamed AI output via `wire:stream`) is
  ordinary Livewire — no JavaScript build required.

## Blade building blocks

```blade
<x-telemetry-ui::card title="Scaling decisions" span="2">
    <x-telemetry-ui::chart :series="$series" type="bar" unit="events/min" />
</x-telemetry-ui::card>
```

`<x-telemetry-ui::chart>` wraps the bundled ECharts build (`line`, `area`,
`bar`). Charts re-render automatically when the card's data changes.
