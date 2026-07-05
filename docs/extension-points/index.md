---
title: Developer integrations
description: Build custom cards, pages, widgets, drivers and tools on the card kit — reuse the query + chart engine, don't rebuild it
weight: 1
---

# Developer integrations

Telemetry UI isn't just a finished dashboard — it's a toolkit. A card is a
Livewire component with a query engine, a chart engine, scope/tenancy and
lazy-loading already wired in, so a new component is a few lines, not a project.
This page is the map; the linked pages go deep.

You can:

- **Add cards** to any page (or a whole new page / "module").
- **Replace or remove** built-in cards and pages (white-label the dashboard).
- **Embed** any card as a widget on your own pages.
- **Add backends** (custom drivers / "exporters").
- **Add MCP tools** for agents.
- Hook **auth** and **multi-tenant scope**.

## Build a card

Every card extends `Cbox\TelemetryUi\Cards\Card`. The terse path — a whole
metric chart in three lines — is `promChart()`:

```php
use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;

final class QueueDepth extends Card
{
    public function render(): View
    {
        // Queries the range, converts the series, catches backend errors,
        // draws the shared chart — with the current scope already applied.
        return $this->promChart('Queue depth', $this->metric('queue_size'), unit: 'number', stat: 'Now');
    }
}
```

Register it and it inherits scope, zoom, deploy annotations, lazy-loading,
embedding and the gate:

```php
TelemetryUi::card(QueueDepth::class, page: 'jobs');
```

Need more control? Build the series yourself and call `chartCard()`, or return
your own Blade view using the [components](#blade-components) — the built-in
cards do both. Always query through `$this->metrics()/traces()/logs()` and catch
`SourceException`.

### The card toolkit

Everything below is a `protected` method on `Card` — the engine you reuse:

| Group | Methods | What you get |
| --- | --- | --- |
| **Time** | `range()` → `[start, end]` · `period()` · `rangeSeconds()` · `promDuration()` · `rateWindow()` | The selected window / preset / zoom, and PromQL-ready duration strings. |
| **Scope** (service/env + tenancy, escaped) | `metric($name, $extra='')` · `traceScope($extra='')` · `logSelector($extra='')` · `scopeMatchers()` (override) · `escapeLabelValue()` | Queries scoped to the active service/environment and the per-viewer scope lock — you never build matchers by hand. |
| **Backends** | `metrics()` · `traces()` · `logs()` · `issues()` (each takes an optional connection name) | The configured drivers, resolved lazily (custom drivers included). |
| **Query helpers** | `total($promql)` · `sumSamples($samples)` · `trendByKey($promql, $start, $end, $key)` | Common aggregations without boilerplate. |
| **Charts** | `promChart(...)` · `chartCard($title, $series, $stats, $type, $unit, …)` · `toChartSeries($timeSeries, $label)` · `stat($label, $value, $tone)` | The ECharts engine + stat tiles. `promChart` is one call; `chartCard` + `toChartSeries` is the flexible path. Deploy annotations, drag-zoom, tooltips and the error/empty states are handled. |
| **Annotations** | `annotations()` · `annotationMarks()` | Deploy/incident markers for the scope, ready for a chart. |
| **Lazy** | `placeholder()` (override) | The skeleton shown while the card streams in. |

`promChart(string $title, string $promql, ?string $subtitle = null, ?string $seriesLabel = null, string $type = 'line', ?string $unit = null, int $span = 1, ?string $stat = null, ?string $statQuery = null)` — a grouped query (`sum by (x)(…)`) yields multiple lines; `$unit` (`bytes`/`ms`/`ratio`/…) picks the stat formatter.

## Blade components

For table or bespoke cards, wrap your view in these (namespace `telemetry-ui`):

| Component | Use |
| --- | --- |
| `<x-telemetry-ui::card title=… subtitle=… span="2">` | The card shell (header, actions slot, span). |
| `<x-telemetry-ui::stats :items="$stats" />` | A row of stat tiles (from `stat()`). |
| `<x-telemetry-ui::chart … />` | The ECharts canvas, if you're not using `chartCard()`. |
| `<x-telemetry-ui::sparkline :points="…" />` | Inline row sparkline. |
| `<x-telemetry-ui::scope-switcher />` · `<x-telemetry-ui::period-selector />` | The scope/time controls (already in the dashboard chrome). |

## Register: add, replace, remove

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity'); // a page (group = a "module")
TelemetryUi::card(MyCard::class, page: 'autoscale');            // add
TelemetryUi::setCards('dashboard', [MyHeadline::class]);        // replace a page's cards
TelemetryUi::removeCard(JobsOverview::class, 'dashboard');      // remove one
TelemetryUi::removePage('users');                              // remove a section
```

Detail (drill-down) pages, hidden pages and the `ScopesTo*` traits are in
[custom detail pages](detail-pages.md); the full card guide (subscribing to
events, `wire:stream`, conventions) is in [custom cards](custom-cards.md).

## The rest of the surface

- **[Embed cards as widgets](../cookbook/embed-widgets.md)** — drop a card on your
  own page with `@telemetryUiAssets` + `<livewire:telemetry-ui.my-card … />`.
- **[Custom drivers](custom-drivers.md)** — `ConnectionManager::extend('victoriametrics', fn ($config) => new MyDriver(...))` to add a backend; cards depend only on the contracts.
- **[Issue trackers](issue-trackers.md)** — add a tracker (or a list of repos) implementing `IssuesSource`.
- **[MCP server](../cookbook/mcp.md)** — `TelemetryUi::mcpTool(MyTool::class)` exposes a read tool to agents.
- **[Authorization & tenancy](../core-concepts/authorization.md)** — the `viewTelemetryUi` / `manageTelemetryUi` gates, `TelemetryUi::restrictScopeUsing()` to lock a viewer to services/environments, and `TelemetryUi::resolveConnectionsUsing()` for per-tenant backends.
- **Events** — listen to `Cbox\TelemetryUi\Events\DashboardViewed` (audit / usage metering: who viewed which page in which scope) and `Cbox\TelemetryUi\Events\BackendQueried` (backend load metering: url, method, duration, ok — one per real backend hit, cached reads excluded).
- **Branding** — `telemetry-ui.brand` config sets the sidebar `name`/`logo` and `accent` colour to white-label the dashboard; for deeper changes, publish and override the namespaced `telemetry-ui::` views.

## Conventions

- Query through `$this->metrics()/traces()/logs()` so named connections, custom
  drivers and tenancy keep working.
- Catch `SourceException` and render an inline error (the chart helpers do this
  for you) — a broken backend must never take the page down.
- Respect `$this->range()`; don't hardcode time windows.
- Boot stays cheap: register class-strings, never instantiate connectors in a
  service provider.
