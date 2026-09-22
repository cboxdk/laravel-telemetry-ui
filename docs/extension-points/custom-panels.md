---
title: Custom panels
description: Ship dashboard pages and panels from your own packages
weight: 1
---

# Custom panels

Any package (or the app itself) can contribute pages and panels. A panel is a
PHP class that returns a typed payload; the SPA renders it. No JavaScript
build, no views, and whatever PromQL/TraceQL/LogQL fits — including metrics and
spans the core UI knows nothing about.

```php
// e.g. in cboxdk/queue-autoscale's service provider
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    if (class_exists(TelemetryUi::class)) {
        TelemetryUi::page('autoscale', 'Autoscale', group: 'Queues');
        TelemetryUi::panel(\Cbox\QueueAutoscale\Ui\ScalingDecisions::class, page: 'autoscale');
        TelemetryUi::panel(\Cbox\QueueAutoscale\Ui\WorkerFleet::class, page: 'autoscale');
    }
}
```

That's all the wiring you need — **no routes to register.** The page is served
at `{path}/p/autoscale`, its panel list at `/api/v2/pages/autoscale`, and each
panel at `/api/v2/panels/{id}`, where the id is the kebab-cased class basename
(`ScalingDecisions` → `scaling-decisions`). Keep basenames unique across the
panels you register; the first match wins.

`page()` also takes an optional `detectMetric:` name pattern: the page only
shows when a matching metric exists in the backend — the same autodetection
the built-in Statamic group uses. `group:` places it under a sidebar heading.

## A chart panel

The terse path for "run a PromQL range query, draw it" is `promChart()`:

```php
use Cbox\TelemetryUi\Panels\Panel;

final class ScalingDecisions extends Panel
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

It queries the range, converts the series, catches backend errors and returns
a `chart` payload with deploy annotations and range bounds, all within the
current scope. A grouped query (`sum by (x)`) yields one line per group. Pass
`stat: 'Now'` for a headline tile.

For more control, build the series yourself and call `chartCard()`:

```php
public function data(): array
{
    [$start, $end] = $this->range();

    try {
        $series = $this->metrics()->queryRange($this->metric('queue_size')->sumBy('queue'), $start, $end);
    } catch (SourceException $e) {
        return $this->chartCard('Queue depth', error: $e->getMessage());
    }

    return $this->chartCard(
        title: 'Queue depth',
        series: $this->toChartSeries($series, label: 'queue'),
        stats: [$this->stat('Queues', (string) count($series))],
        type: 'area',
        unit: 'number',
    );
}
```

## A table panel

```php
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

final class WorkerFleet extends Panel
{
    public function data(): array
    {
        $columns = [Ui::col('host', 'Host'), Ui::num('workers', 'Workers')];

        try {
            $samples = $this->metrics()->query($this->metric('autoscale_workers')->sumBy('host_name'));
        } catch (SourceException $e) {
            return Ui::table('Worker fleet', $columns, [], ['error' => $e->getMessage()]);
        }

        $rows = [];

        foreach ($samples as $sample) {
            $host = $sample->labels['host_name'] ?? '?';
            $rows[] = [
                '_link' => Ui::entity('host', $host),
                'host' => Ui::cell($host, ['dim' => ['key' => 'host.name', 'value' => $host]]),
                'workers' => Ui::cell(Format::count($sample->value), ['raw' => $sample->value, 'mono' => true]),
            ];
        }

        return Ui::table('Worker fleet', $columns, $rows, ['empty' => 'No workers reporting.']);
    }
}
```

`_link` makes the whole row open the host's entity page. `dim` turns the cell
into a dimension chip (filter, exclude, group by, open). The full list of kinds,
cell options and link builders is in
[pages & panels](../core-concepts/pages-and-panels.md#the-payload-contract).

## Params and controls

Public properties marked `#[Param('x')]` are filled from the `?x=` query
parameter before `data()` runs (`string`, `int`, `float` and `bool` are cast).
Pair one with a control and the SPA renders it in the panel header and
re-fetches the panel with the new value:

```php
use Cbox\TelemetryUi\Panels\Attributes\Param;

final class SlowJobs extends Panel
{
    #[Param('min_ms')]
    public int $minMs = 1000;

    #[Param('queue')]
    public string $queue = '';

    public function data(): array
    {
        // …query with $this->minMs and $this->queue…

        return Ui::table('Slow jobs', $columns, $rows, [
            'controls' => [
                Ui::select('queue', 'Queue', $this->queue, Ui::options($queues)),
                Ui::search('min_ms', 'Min ms', (string) $this->minMs, '1000'),
            ],
        ]);
    }
}
```

`Ui::param('queue', 'emails')` as a cell `link` sets the param from a click
(filter the panel to a value). Override `boot()` if a param needs normalising
after binding.

## The panel toolkit

Everything below is a `protected` method on `Panel`:

| Group | Methods | What you get |
| --- | --- | --- |
| **Time** | `range()` · `period()` · `rangeSeconds()` · `promDuration()` · `rateWindow()` | The selected window (preset or brushed range) and PromQL-ready durations. |
| **Scope** | `metric($name, $extra = '')` · `traceQuery(...$conditions)` · `traceScope($extra = '')` · `logSelector()` · `scopeMatchers()` (override) · `escapeLabelValue()` | Queries scoped to the active service/environment and the viewer's scope lock. |
| **Backends** | `metrics()` · `traces()` · `logs()` · `issues()` (optional connection name) | The configured drivers, resolved lazily. |
| **Query helpers** | `total($query)` · `sumSamples($samples)` · `counterIncrease($query)` · `trendByKey($query, $start, $end, $key)` | Common aggregations and per-row sparkline data. |
| **Charts** | `promChart(...)` · `chartCard(...)` · `toChartSeries($series, $label)` · `stat()` · `statDelta()` | `chart` payloads and stat tiles. |
| **Annotations** | `annotations()` · `annotationMarks()` | Deploy/incident markers for the scope. |
| **Links** | `pageLink($page, $extra)` | A `Ui::page()` link with empty params dropped. |

The scope is also available as `$this->scope` (the `RequestScope`), and
`$this->period`, `$this->from`, `$this->to`, `$this->service` and
`$this->environment` are set from it.

## Add, replace, remove

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;

// Add — append a panel to any page (default: the dashboard).
TelemetryUi::panel(MyPanel::class, page: 'requests');

// Replace — swap a page's whole panel list for your own (a branded dashboard).
TelemetryUi::setPanels('dashboard', [MyHeadline::class, MyChart::class]);

// Remove — drop a single built-in panel…
TelemetryUi::removePanel(JobsOverview::class, 'dashboard');

// …or a whole page from the navigation and the API.
TelemetryUi::removePage('users');
```

Re-registering a page slug with `TelemetryUi::page(...)` overwrites it, so you
can relabel or regroup a built-in page. To *extend* a built-in panel instead of
replacing it, subclass it (the overview panels that detail pages reuse are not
`final`) and register your subclass.

## Conventions

- Query through `$this->metrics()` / `traces()` / `logs()` so named
  connections, custom drivers and tenancy keep working.
- **Never throw from `data()`.** Catch `SourceException` and return the payload
  with an `error` key; a broken backend must not take the page down.
- Respect `$this->range()`; don't hardcode time windows.
- Return links as `Ui::*` link arrays, never URLs — the SPA owns routing and
  the base path.
- Boot stays cheap: register class-strings, never instantiate connectors in a
  service provider.
