# Upgrade guide

## 1.x → 2.0

v2 removes Livewire. The dashboard is now a versioned JSON API
(`{path}/api/v2/*`) plus a prebuilt React app the package serves from
`public/build`. The query layer (contracts, drivers, the query IR, result DTOs),
`Analysis/`, the MCP server and every connection/config hook are unchanged —
the breaking changes are all in the presentation layer.

### If you only use the built-in screens

1. `composer update cboxdk/laravel-telemetry-ui` to `^2.0`. `livewire/livewire`
   is no longer required; remove it if nothing else in your app uses it.
2. If you published the config, rename the `cards` key to `panels` and update
   the class names (see below).
3. Update any bookmarks or host links to dashboard pages: `/telemetry-ui/jobs`
   is now `/telemetry-ui/p/jobs` (see [Routes](#routes-and-deep-links)).
4. Raise `telemetry-ui.throttle` if you set it yourself. The SPA sends one
   small request per panel, facet list and Explore query, so the default moved
   from `120,1` to `600,1`.

Nothing else is needed. Hosts don't need Node: the built SPA ships in the
package.

### Cards are now panels

Everything that was a card is now a **panel**: a plain PHP class with no view,
no component state and no framework lifecycle.

| 1.x | 2.0 |
| --- | --- |
| `Cbox\TelemetryUi\Cards\Card` | `Cbox\TelemetryUi\Panels\Panel` |
| `Cbox\TelemetryUi\Cards\Builtin\*` | `Cbox\TelemetryUi\Panels\Builtin\*` (same basenames) |
| `Cbox\TelemetryUi\Cards\Builtin\Detail\*` | `Cbox\TelemetryUi\Panels\Builtin\Detail\*` |
| `TelemetryUi::card($class, page: …)` | `TelemetryUi::panel($class, page: …)` |
| `TelemetryUi::setCards($page, [...])` | `TelemetryUi::setPanels($page, [...])` |
| `TelemetryUi::removeCard($class, $page)` | `TelemetryUi::removePanel($class, $page)` |
| `TelemetryUi::cards($page)` | `TelemetryUi::panels($page)` |
| config `telemetry-ui.cards` | config `telemetry-ui.panels` |
| `render(): View` | `data(): array` (a typed payload built with `Ui::*`) |
| `#[Livewire\Attributes\Url(as: 'x')]` | `#[Cbox\TelemetryUi\Panels\Attributes\Param('x')]` |
| `pageUrl($page, $extra): string` | `pageLink($page, $extra): array` / `Ui::page()` |
| `placeholder()` (lazy skeleton) | removed — the SPA draws its own loading state |

`TelemetryUi::page()`, `removePage()`, `navLink()`, `connection()`,
`restrictScopeUsing()`, `resolveConnectionsUsing()`, `viewState()` and
`mcpTool()` are unchanged.

Registration:

```php
// before
use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;

TelemetryUi::card(QueueDepth::class, page: 'jobs');
TelemetryUi::setCards('dashboard', [MyHeadline::class]);
TelemetryUi::removeCard(JobsOverview::class, 'dashboard');

// after
use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;

TelemetryUi::panel(QueueDepth::class, page: 'jobs');
TelemetryUi::setPanels('dashboard', [MyHeadline::class]);
TelemetryUi::removePanel(JobsOverview::class, 'dashboard');
```

Config:

```php
// before — config/telemetry-ui.php
'cards' => [
    \Cbox\TelemetryUi\Cards\Builtin\RequestsActivity::class,
    \Cbox\TelemetryUi\Cards\Builtin\RequestDuration::class,
],

// after
'panels' => [
    \Cbox\TelemetryUi\Panels\Builtin\RequestsActivity::class,
    \Cbox\TelemetryUi\Panels\Builtin\RequestDuration::class,
],
```

A `cards` key left in a published config is ignored.

### `render(): View` → `data(): array`

A panel returns an array with a `kind` the SPA has a renderer for (`chart`,
`stats`, `table`, `bars`, `composite`, `heatmap`, `graph`, `logs`, `header`,
`kv`, `code`, `callout`, `hidden`). Build it with the `Cbox\TelemetryUi\Panels\Ui`
builders or the chart helpers on the base class. The full list is in
[pages & panels](docs/core-concepts/pages-and-panels.md#the-payload-contract).

```php
// before
public function render(): View
{
    return view('my-package::cards.top-queues', ['rows' => $rows]);
}

// after
public function data(): array
{
    return Ui::table('Top queues', [
        Ui::col('queue', 'Queue'),
        Ui::num('jobs', 'Jobs'),
    ], $rows);
}
```

`promChart()`, `chartCard()`, `stat()`, `statDelta()` and `toChartSeries()`
still exist and now return payload arrays instead of views. The Blade
components (`<x-telemetry-ui::card>`, `::chart`, `::stats`, `::sparkline`,
`::scope-switcher`, `::period-selector`) and every `telemetry-ui::` view are
gone.

A panel must not throw from `data()`. Catch `SourceException` and return the
payload with an `error` key, as before (`chartCard(error: …)`, or
`'error' => $message` in the `$extra` array of any `Ui::*` builder).

### `#[Url]` → `#[Param]`

Query-string binding for panel properties (the entity key of a detail panel, a
panel control):

```php
// before
use Livewire\Attributes\Url;

#[Url(as: 'job')]
public string $job = '';

// after
use Cbox\TelemetryUi\Panels\Attributes\Param;

#[Param('job')]
public string $job = '';
```

`#[Param]` works on public `string`, `int`, `float` and `bool` properties. The
scope properties (`period`, `from`, `to`, `service`, `environment`) are filled
for you from the request scope; don't redeclare them. There is no replacement
for Livewire's `#[On(...)]` listeners: a panel is re-fetched when the scope or
its params change.

### Links are data: `pageUrl()` → `Ui::*` links

The SPA owns routing and the base path, so a panel says *what* a link opens and
the client decides where that lives. Links are arrays built with:

| Builder | Opens |
| --- | --- |
| `Ui::entity($type, $value)` | an entity page (`Ui::entity('route', 'GET /checkout')`) |
| `Ui::page($page, $params)` | a registered page, with extra params |
| `Ui::trace($traceId)` | the trace drawer |
| `Ui::error($group)` | an error group |
| `Ui::issue($id)` | a tracker issue |
| `Ui::explore($signal, $where)` | Explore, pre-filtered |
| `Ui::param($param, $value)` | set one of this panel's own params |
| `Ui::url($href)` | an external URL |

```php
// before
public function detailUrl(string $job): string
{
    return $this->pageUrl('job-detail', ['job' => $job]);
}
// …and in Blade: <a href="{{ $this->detailUrl($row['job']) }}">

// after — put the link on the cell, or `_link` on the row for whole-row drill-down
$rows[] = [
    '_link' => Ui::entity('job', $job),
    'job' => Ui::cell($job, ['link' => Ui::entity('job', $job)]),
];
```

`$this->pageLink($page, $extra)` is the protected helper on `Panel` that
replaces `pageUrl()`; it returns `Ui::page(...)` with empty params dropped.

### Embedding cards in host Blade pages is removed

`@telemetryUiAssets`, `<livewire:telemetry-ui.*>` widgets (including
`<livewire:telemetry-ui.trace-drawer />`) and the `:embedded` prop are gone,
with no replacement in 2.0. If you embedded cards:

- link to the dashboard page instead (`/telemetry-ui/p/{page}`, or an entity
  page such as `/telemetry-ui/entity/route?value=GET%20/checkout`), or
- read the data from the JSON API (`GET /telemetry-ui/api/v2/panels/{panel}`)
  and render it yourself. The API runs behind the same `viewTelemetryUi` gate
  and scope lock. See [the API reference](docs/core-concepts/api.md).

### Routes and deep links

| 1.x | 2.0 |
| --- | --- |
| `/telemetry-ui` | `/telemetry-ui` (Overview) |
| `/telemetry-ui/{page}` | `/telemetry-ui/p/{page}` |
| `/telemetry-ui/traces/{id}` | unchanged (now an SPA route) |
| `/telemetry-ui/job-detail?job=…` (and other detail pages) | `/telemetry-ui/entity/job?value=…` (or `/telemetry-ui/p/job-detail?job=…`) |
| `?trace=…`, `?issue=…`, `?exception=…` drawer params | `?drawer=trace:…~error:…~issue:…` (a stack, bottom first) |
| `/telemetry-ui/assets/{asset}` | `/telemetry-ui/build/{path}` (hashed chunks) |
| route name `telemetry-ui.page` | `telemetry-ui.spa` (catch-all; build the path yourself) |
| route name `telemetry-ui.trace` | `telemetry-ui.spa` |

Every path under `{path}` that isn't `api/` or `build/` returns the SPA shell,
so deep links survive a reload. Old `/telemetry-ui/{page}` URLs render the
SPA's "not found" screen.

```php
// before
route('telemetry-ui.page', ['page' => 'jobs', 'period' => '24h']);

// after
url(config('telemetry-ui.path').'/p/jobs?period=24h');
```

### Other removals

- The `telemetry-ui:period-changed` and `telemetry-ui:refresh` Livewire events.
  Scope changes live in the SPA's URL; the reader's window is still reported to
  the server (`POST /api/v2/view-state`), so `ViewStateChanged` and
  `TelemetryUi::viewState()` work as before.
- The gate middleware on `/livewire/update`. The gate now runs on every
  dashboard route, including every API call.
- `public/telemetry-ui.js` and `public/telemetry-ui.css`. The SPA's own build in
  `public/build` replaces them.

### Custom card → panel, end to end

```php
// 1.x
namespace App\Telemetry;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

final class TopQueues extends Card
{
    #[Url(as: 'connection')]
    public string $connection = '';

    public function render(): View
    {
        $rows = [];
        $error = null;

        try {
            foreach ($this->metrics()->query(
                $this->metric('queue_depth', $this->connection !== '' ? 'connection="'.$this->escapeLabelValue($this->connection).'"' : '')->sumBy('queue'),
            ) as $sample) {
                $rows[] = ['queue' => $sample->labels['queue'] ?? '?', 'depth' => $sample->value];
            }
        } catch (SourceException $e) {
            $error = $e->getMessage();
        }

        return view('app::telemetry.top-queues', ['rows' => $rows, 'error' => $error]);
    }

    public function detailUrl(string $queue): string
    {
        return $this->pageUrl('queue-detail', ['queue' => $queue]);
    }
}
```

```php
// 2.0
namespace App\Telemetry;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

final class TopQueues extends Panel
{
    #[Param('connection')]
    public string $connection = '';

    public function data(): array
    {
        $columns = [Ui::col('queue', 'Queue'), Ui::num('depth', 'Depth')];

        try {
            $samples = $this->metrics()->query(
                $this->metric('queue_depth', $this->connection !== '' ? 'connection="'.$this->escapeLabelValue($this->connection).'"' : '')->sumBy('queue'),
            );
        } catch (SourceException $e) {
            return Ui::table('Top queues', $columns, [], ['error' => $e->getMessage()]);
        }

        $rows = [];

        foreach ($samples as $sample) {
            $queue = $sample->labels['queue'] ?? '?';
            $rows[] = [
                '_link' => Ui::entity('queue', $queue),
                'queue' => Ui::cell($queue, ['link' => Ui::entity('queue', $queue)]),
                'depth' => Ui::cell(Format::count($sample->value), ['raw' => $sample->value, 'mono' => true]),
            ];
        }

        return Ui::table('Top queues', $columns, $rows, [
            'controls' => [Ui::search('connection', 'Connection', $this->connection)],
            'empty' => 'No queued jobs in this period.',
        ]);
    }
}
```

The Blade view is deleted. Registration changes from `card()` to `panel()`; the
panel's API id is its kebab-cased basename (`top-queues`), served at
`/api/v2/panels/top-queues`.


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
