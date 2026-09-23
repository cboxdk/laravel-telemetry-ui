---
title: Developer integrations
description: Build custom panels, pages, dimensions, drivers and tools — reuse the query + chart engine, don't rebuild it
weight: 40
---

# Developer integrations

Telemetry UI isn't just a finished dashboard — it's a toolkit. A panel is a
plain PHP class with a query engine, chart helpers and scope/tenancy already
wired in, so a new panel is a few lines, not a project. This page is the map;
the linked pages go deep.

You can:

- **Add panels** to any page (or a whole new page / "module").
- **Replace or remove** built-in panels and pages (white-label the dashboard).
- **Declare dimensions** so your own attributes become facets, chips and
  entity pages.
- **Attach detail panels** to an entity page.
- **Read the JSON API** from your own code or pages.
- **Add backends** (custom drivers).
- **Add MCP tools** for agents.
- Hook **auth** and **multi-tenant scope**.

## Build a panel

Every panel extends `Cbox\TelemetryUi\Panels\Panel` and returns a payload from
`data()`. The terse path — a whole metric chart in one call — is `promChart()`:

```php
use Cbox\TelemetryUi\Panels\Panel;

final class QueueDepth extends Panel
{
    public function data(): array
    {
        // Queries the range, converts the series, catches backend errors,
        // returns a chart payload — with the current scope already applied.
        return $this->promChart('Queue depth', $this->metric('queue_size'), unit: 'number', stat: 'Now');
    }
}
```

Register it and it inherits scope, brush-to-zoom, deploy annotations,
auto-refresh and the gate:

```php
TelemetryUi::panel(QueueDepth::class, page: 'jobs');
```

Need more control? Build the series yourself and call `chartCard()`, or return
a table, stats row, bars or any other kind with the `Ui` builders. The kinds
are listed in [pages & panels](../core-concepts/pages-and-panels.md#the-payload-contract);
the toolkit and conventions are in [custom panels](custom-panels.md).

## Declare a panel instead of coding it

A chart over a metric needs no class — useful when the metric comes from a
sidecar in another language (Go, Rust, anything exporting OTLP), where there
is no PHP to hang a panel on:

```php
TelemetryUi::page('indexer', 'Indexer', group: 'Infrastructure');
TelemetryUi::setPanels('indexer', []);            // no built-ins on this page

TelemetryUi::metricPanel('indexer-queue', page: 'indexer',
    title: 'Queue depth', metric: 'indexer_queue_depth', type: 'area', stat: 'Now');

TelemetryUi::metricPanel('indexer-docs', page: 'indexer', span: 2,
    title: 'Documents indexed', metric: 'indexer_docs_total', rate: true, by: 'status');

TelemetryUi::metricPanel('indexer-latency', page: 'indexer',
    title: 'Latency p95', metric: 'indexer_duration_seconds_bucket', quantile: 0.95, unit: 'ms');
```

A gauge reads as itself, `rate: true` turns a counter into per-minute
throughput, `quantile:` reads a histogram, `by:` splits the series by a label
and `where:` adds label matchers. Declared panels get the same scope, gate,
auto-refresh, deploy markers and brush-to-zoom as coded ones — they are just
configuration instead of code. Reach for a panel class when the payload isn't a chart
(tables, composites, stories).

## A routing layer as its own area

Some layers name their requests rather than emitting their own attribute:
Livewire writes `livewire:{component}`, and a host's own layer might write
`hubhus:{screen}`. One call makes that a first-class part of the dashboard:

```php
TelemetryUi::routeFamily('hubhus', label: 'Screens',
    pattern: 'hubhus:{value}', dimension: 'hubhus.screen');
```

That registers a page (the family's throughput plus a per-value table with the
prefix stripped, each row opening that value's page) **and** declares
`hubhus.screen` as a [derived dimension](../core-concepts/dimensions-and-explore.md#a-dimension-that-lives-inside-another-attribute),
so the same values become facets, chips, filters and entity pages everywhere
else.

## Register: add, replace, remove

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

TelemetryUi::page('autoscale', 'Autoscale', group: 'Queues'); // a page (group = a "module")
TelemetryUi::panel(MyPanel::class, page: 'autoscale');       // add
TelemetryUi::setPanels('dashboard', [MyHeadline::class]);    // replace a page's panels
TelemetryUi::removePanel(JobsOverview::class, 'dashboard');  // remove one
TelemetryUi::removePage('users');                            // remove a section
```

## Declare dimensions

```php
TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus',
    link: fn ($id) => route('customers.show', $id));
```

The attribute becomes a facet, a group-by option, a filter chip and a
clickable chip on every trace, and gets an entity page at
`/entity/hubhus.customer_id?value=…`. See
[dimensions & Explore](../core-concepts/dimensions-and-explore.md).

Detail panels scoped to one entity, hidden pages, `entityPage()` and the
`ScopesTo*` traits are in [custom detail pages](detail-pages.md).

## The rest of the surface

- **[JSON API](../core-concepts/api.md)** — every screen's data under
  `{path}/api/v2`, same gate and scope lock. Embedding cards as Livewire widgets
  was removed in v2; link to the SPA or read the API instead.
- **[Custom drivers](custom-drivers.md)** — `ConnectionManager::extend('victoriametrics', fn ($config) => new MyDriver(...))` to add a backend; panels depend only on the contracts.
- **[Issue trackers](issue-trackers.md)** — add a tracker (or a list of repos) implementing `IssuesSource`.
- **[View state](view-state.md)** — `TelemetryUi::viewState()` to read (and move) the reader's time window, auto-refresh interval and scope, plus the `ViewStateChanged` event; it survives reload and links that carry no query string.
- **[Connection switcher](connection-switcher.md)** — `TelemetryUi::connection()` puts your backend profiles in the dashboard header, so switching doesn't mean leaving.
- **[MCP server](../cookbook/mcp.md)** — `TelemetryUi::mcpTool(MyTool::class)` exposes a read tool to agents.
- **[Authorization & tenancy](../core-concepts/authorization.md)** — the `viewTelemetryUi` / `manageTelemetryUi` gates, `TelemetryUi::restrictScopeUsing()` to lock a viewer to services/environments, and `TelemetryUi::resolveConnectionsUsing()` for per-tenant backends.
- **Events** — listen to `Cbox\TelemetryUi\Events\DashboardViewed` (audit / usage metering: who opened which page in which scope — fired when the SPA shell is served, so client-side navigation inside the app does not fire it again), `Cbox\TelemetryUi\Events\BackendQueried` (backend load metering: url, method, duration, ok — one per real backend hit, cached reads excluded) and `Cbox\TelemetryUi\Events\ViewStateChanged` (the reader moved the time window, refresh interval or scope).
- **Branding** — `telemetry-ui.brand` config sets the `name`/`logo` and `accent` colour to white-label the dashboard. There are no views to override in v2; the SPA is prebuilt.

## Conventions

- Query through `$this->metrics()/traces()/logs()` so named connections, custom
  drivers and tenancy keep working.
- Never throw from `data()`: catch `SourceException` and return the payload with
  an `error` key (the chart helpers do this for you) — a broken backend must
  never take the page down.
- Respect `$this->range()`; don't hardcode time windows.
- Return links as `Ui::*` arrays, not URLs.
- Boot stays cheap: register class-strings, never instantiate connectors in a
  service provider.
