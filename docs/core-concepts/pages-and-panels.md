---
title: Pages & panels
description: The panel model behind every screen, and the typed payload contract the SPA renders
weight: 2
---

# Pages & panels

Most screens in the dashboard are **pages** made of **panels**. A panel is a
plain PHP class extending `Cbox\TelemetryUi\Panels\Panel`: it runs its queries
and returns one JSON payload from `data()`. The React app fetches that payload
from `GET {path}/api/v2/panels/{id}` and renders it. There is no view, no
component state and no JavaScript on your side.

Pages form the sidebar (grouped: Activity, Queues, Frontend, Infrastructure,
…). There is no difference between a built-in panel and a third-party one —
the Requests page is built from the same primitives a queue-autoscale package
would use.

Explore, entity pages, the trace view and error groups are not panel pages;
they have their own endpoints. See [dimensions & Explore](dimensions-and-explore.md).

## Registering

Config-declared dashboard panels (rendered first, in order):

```php
// config/telemetry-ui.php
'panels' => [
    \Cbox\TelemetryUi\Panels\Builtin\RequestsActivity::class,
],
```

Runtime registration from any service provider:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::page('autoscale', 'Autoscale', group: 'Queues');
    TelemetryUi::panel(AutoscaleDecisions::class, page: 'autoscale');
}
```

Both calls are data-only (arrays of class-strings), so registering costs
nothing at boot. A registered page is reachable at `{path}/p/{slug}` with no
route of your own.

| Method | Does |
| --- | --- |
| `page($slug, $label, group:, icon:, detectMetric:, hidden:)` | Register (or overwrite) a page. |
| `removePage($slug)` | Remove a page and its panels. |
| `panel($class, page: 'dashboard')` | Append a panel to a page. |
| `setPanels($page, [...])` | Replace a page's panels (`[]` blanks it). |
| `removePanel($class, $page = 'dashboard')` | Drop one panel. |
| `panels($page = 'dashboard')` | The effective list, config panels first. |

A panel's id is its kebab-cased class basename (`RoutesTable` →
`routes-table`). Its grid width is `static span()` (1–3 columns, default 1).

## Autodetected pages

Pages registered with a `detectMetric` pattern only appear when the metrics
backend actually contains matching metric names:

```php
TelemetryUi::page('autoscale', 'Autoscale', group: 'Queues', detectMetric: 'autoscale_.*');
```

Detection is cached per pattern (TTL `telemetry-ui.detection.ttl`, default
300s) and scoped to the selected service/environment. Undetected pages are left
out of the navigation that `GET /api/v2/bootstrap` returns, and
`GET /api/v2/pages/{page}` answers 404. If the backend is unreachable,
detection fails open — the page stays visible and its panels show their own
error states.

Every registered pattern is resolved in **one** backend call per request: the
driver returns the metric names matching any of them and each pattern is
decided against that list. On Prometheus/Mimir that is a single
`/api/v1/label/__name__/values` with a `match[]` selector. A driver that does
not implement [`EnumeratesMetricNames`](../extension-points/custom-drivers.md)
falls back to one `count({__name__=~"autoscale_.*"})` query per pattern.

The built-in **Statamic** group works this way: install
[`cboxdk/statamic-telemetry`](https://github.com/cboxdk/statamic-telemetry)
in any monitored app and its `statamic_*` metrics light up the Static Cache,
Stache, Glide, Forms, Content and Inventory pages — the dashboard app itself
does not need to be a Statamic app.

## What a panel gets for free

```php
use Cbox\TelemetryUi\Panels\Panel;

final class AutoscaleDecisions extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        return $this->promChart(
            'Scaling decisions',
            $this->metric('autoscale_scaling_events_total')->rate($this->rateWindow())->sumBy('queue')->times(60),
            seriesLabel: 'queue',
            type: 'bar',
            unit: 'events/min',
            span: 2,
        );
    }
}
```

- The constructor receives the request's `RequestScope`, so `range()`,
  `period()`, `promDuration()` and `rateWindow()` reflect the global time
  window (preset or brushed range) and `metric()`, `traceQuery()` and
  `logSelector()` are already scoped to the selected service/environment and
  the viewer's tenancy lock.
- `metrics()`, `traces()`, `logs()` and `issues()` return the configured
  drivers.
- Public properties marked `#[Param('x')]` are filled from `?x=` (see
  [custom panels](../extension-points/custom-panels.md#params-and-controls)).
- The SPA re-fetches the panel whenever the scope, the filters or its params
  change, and on each auto-refresh tick. One slow panel never blocks the page:
  each is its own request.

A panel must not throw from `data()`. Catch `SourceException` and return the
payload with an `error` key — the chart helpers do this for you.

## The payload contract

Every payload is an array with a `kind`. `Cbox\TelemetryUi\Panels\Ui` has a
builder for most kinds, and the chart helpers on `Panel` build `chart`. The
shapes are mirrored 1:1 by `resources/app/src/api/types.ts`.

| Kind | Build with | Renders |
| --- | --- | --- |
| `chart` | `promChart()` · `chartCard()` | Line/area/bar time series with stat tiles, deploy annotations and brush-to-zoom. |
| `stats` | `Ui::stats($title, $items)` | A row of stat tiles (`stat()` / `statDelta()` items, optional sparkline). |
| `table` | `Ui::table($title, $columns, $rows)` | A sortable table. Rows map column key → `Ui::cell()`; `_link` on a row makes the whole row a drill-down. |
| `bars` | `Ui::bars($title, $items)` | Ranked label → value bars (top pages, countries). |
| `composite` | `Ui::composite($title, $parts)` | Several payloads stacked in one panel. |
| `header` | `Ui::header($title, $subtitle, $stats)` | An entity/detail header with headline stats and a `back` link. |
| `kv` | `Ui::kv($title, $items)` | Label/value pairs. |
| `code` | `Ui::code($title, $text, $language)` | A code block (SQL, JSON, a stack trace). |
| `callout` | `Ui::callout($title, $message, $tone)` | A message; tone `info`, `warn`, `danger` or `ok`. |
| `hidden` | `Ui::hidden()` | Nothing — the panel decided not to show. |
| `heatmap` | plain array | `{xs, ys, cells: [[xi, yi, value]], unit?, link?}` |
| `graph` | plain array | `{nodes: [{id, label, …}], edges: [{source, target, count, …}]}` (the service graph) |
| `logs` | plain array | `{entries: [{time, ms, level, tone, message, labels, traceId?}], stream?}` — `stream: {signal, params}` turns on SSE live tail. |

Optional keys on any kind: `title`, `subtitle`, `span`, `error`, `empty` (the
empty-state message), `note`, `drill` (a link in the header) and `controls`
(`Ui::select()` / `Ui::search()` bound to a panel param).

### Cells

`Ui::cell($value, $opts)` takes `raw` (the sort value), `tone`, `mono`, `link`,
`spark` (a sparkline), `bar` (0–1 inline bar), `badge`, `sub` (a second line)
and `dim` (`['key' => 'http.route', 'value' => …]`, which makes the cell a
dimension chip: filter, exclude, group by, open the entity page).
`Ui::col()` / `Ui::num()` declare columns; `num()` right-aligns.

### Links

Links are data, never URLs. The SPA owns routing and the base path, so a link
says *what* it opens:

| Builder | Opens |
| --- | --- |
| `Ui::entity($type, $value)` | An entity page. |
| `Ui::page($page, $params)` | A registered page with extra params. |
| `Ui::trace($traceId)` | The trace, in the drawer. |
| `Ui::error($group)` | An error group, in the drawer. |
| `Ui::issue($id)` | A tracker issue, in the drawer. |
| `Ui::explore($signal, $where)` | Explore, pre-filtered (`['http.route=/checkout']`). |
| `Ui::param($param, $value)` | Set one of this panel's own params and re-fetch. |
| `Ui::url($href)` | An external URL. |

## Custom pages and panels

See [custom panels](../extension-points/custom-panels.md) for the full guide
and [custom detail pages](../extension-points/detail-pages.md) for drill-down
pages scoped to one entity.
