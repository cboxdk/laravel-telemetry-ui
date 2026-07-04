---
title: Custom detail pages
description: Drill a row into its own purpose-built detail page instead of a pre-filtered trace search
weight: 4
---

# Custom detail pages

The Nightwatch-style pattern: clicking a row — a route, a job, an exception,
an outgoing host — doesn't dump you into a pre-filtered trace search. It opens
a dedicated page scoped to that one entity, showing *its* numbers (processed /
failed / p95 for a job, status mix for a route) and drilling progressively
deeper into that entity's traces.

Mechanically a detail page is three things:

1. **A scope** — a `#[Url]` prop (`?job=…`, `?host=…`) plus a `scopeMatchers()`
   override that narrows every query on the card to that entity.
2. **Detail cards** — mostly the *overview* cards you already ship, subclassed
   so the scope applies. Reuse, not rewrite.
3. **A hidden page** — registered like any page, but flagged `hidden: true` so
   it's routable and rendered without cluttering the sidebar or command palette.

Then a table card links each row into it. This walkthrough builds one
end-to-end for a hypothetical "queue" entity — a detail page for a single
named queue.

## 1. The scope

Every `Card` has a `scopeMatchers()` hook (see `src/Cards/Card.php`). It
returns extra PromQL label matchers — a string like `label="value"` — that
`metric()` appends to *every* metric reference on the card, alongside the
global `service_name` / `deployment_environment_name` scope:

```php
protected function metric(string $name, string $extraMatchers = ''): string
{
    // …service + env matchers…
    if (($scope = $this->scopeMatchers()) !== '') {
        $matchers[] = $scope;
    }
    // → metric{service_name="checkout",…,queue="emails"}
}
```

The default returns `''` (unscoped). An entity-detail card overrides it. The
built-ins package this as a small trait per entity — `ScopesToJob`,
`ScopesToRoute`, `ScopesToException`, `ScopesToHost` in
`src/Cards/Builtin/Detail/`. Here's `ScopesToJob` in full, the shape to copy:

```php
namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Livewire\Attributes\Url;

trait ScopesToJob
{
    #[Url(as: 'job')]
    public string $job = '';

    protected function scopeMatchers(): string
    {
        return $this->job === '' ? '' : 'job_name="'.addcslashes($this->job, '"\\').'"';
    }

    protected function jobTraceScope(): string
    {
        return 'span.laravel.job.class = "'.addcslashes($this->job, '"\\').'"';
    }
}
```

Three parts:

- The `#[Url(as: 'job')]` prop binds the entity from the query string, so
  `/telemetry-ui/job-detail?job=App\Jobs\SendMail` restores the scope on load
  and survives period/service changes.
- `scopeMatchers()` returns the PromQL matcher — empty when unscoped, so the
  same card degrades gracefully to "all jobs".
- A `*TraceScope()` helper returns the *TraceQL* condition for the same entity
  (label names differ between Prometheus and Tempo), fed to `traceScope()` on
  the traces card.

For our queue entity, the trait is identical bar the label names:

```php
namespace App\Telemetry\Detail;

use Livewire\Attributes\Url;

trait ScopesToQueue
{
    #[Url(as: 'queue')]
    public string $queue = '';

    protected function scopeMatchers(): string
    {
        return $this->queue === '' ? '' : 'queue="'.addcslashes($this->queue, '"\\').'"';
    }

    protected function queueTraceScope(): string
    {
        return 'span.messaging.destination.name = "'.addcslashes($this->queue, '"\\').'"';
    }
}
```

### Escaping is not optional

Note the `addcslashes($value, '"\\')` on every value. This is the same
escaping `Card::escapeLabelValue()` applies to the service/env scope. The
entity value comes straight from a URL query string — attacker-controllable —
and lands inside a quoted PromQL/TraceQL/LogQL matcher. A raw `"` or `\` would
break out of the string and let the query be rewritten.

**Never string-concatenate a raw label value into a query.** Every value that
crosses into a matcher goes through `addcslashes($value, '"\\')` (or, inside a
`Card` method, `$this->escapeLabelValue(...)`). If you find yourself building a
matcher without it, you have a query-injection bug.

## 2. Detail cards — subclass the overview cards

The point of scoping through `metric()` is that a card written for the
*overview* page works unchanged on the *detail* page once `scopeMatchers()` is
non-empty. So the built-in detail cards mostly just subclass the overview card
and mix in the scope trait. `JobDetailOutcomes` is the whole file:

```php
namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;

final class JobDetailOutcomes extends JobsOverview
{
    use ScopesToJob;
}
```

That's it — `JobsOverview::render()` builds its `sum(increase(queue_jobs_…))`
queries through `$this->metric(...)`, which now appends `job_name="…"`, so the
same processed/released/failed chart is scoped to one job.

For this to work the overview card must be **subclassable** — `class
JobsOverview extends Card`, not `final`. (Overview cards that are drilled into
are intentionally left un-`final`; the leaf detail cards *are* `final`.) Our
queue detail reuses `JobsOverview` the same way:

```php
namespace App\Telemetry\Detail;

use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;

final class QueueDetailOutcomes extends JobsOverview
{
    use ScopesToQueue;
}
```

### A bespoke header card

The one card you write by hand is the header — the entity name, its headline
stats, and a "back" link. Model it on `JobDetailHeader`
(`src/Cards/Builtin/Detail/JobDetailHeader.php`): a `Card` that uses the scope
trait, computes period totals with `metric()` + `total()`, and renders the
shared `telemetry-ui::cards.detail-header` view.

```php
namespace App\Telemetry\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

final class QueueDetailHeader extends Card
{
    use ScopesToQueue;

    public function render(): View
    {
        $p = $this->promDuration();
        $processed = $this->metric('queue_jobs_processed_total');
        $failed = $this->metric('queue_jobs_failed_total');

        $error = null;
        $proc = $fail = 0.0;

        try {
            $proc = $this->total('sum(increase('.$processed.'['.$p.']))');
            $fail = $this->total('sum(increase('.$failed.'['.$p.']))');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->queue === '' ? '(all queues)' : $this->queue,
            'subtitle' => 'Queue detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All jobs',
            'error' => $error,
            'stats' => [
                ['label' => 'Processed', 'value' => Format::count($proc), 'tone' => null],
                ['label' => 'Failed', 'value' => Format::count($fail), 'tone' => $fail > 0 ? 'danger' : 'dim'],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'jobs',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
```

The `backUrl()` carries `period`, `service` and `env` back to the list page so
the drill-down round-trips without losing the active scope.

### The traces card — deepest drill

The last card lists the entity's own traces. Copy `JobDetailTraces`: search
Tempo with `traceScope()` (the global service/env scope) AND-joined with your
`*TraceScope()` condition, and skip the query entirely when the entity is
empty so an unscoped visit doesn't fetch everything.

```php
final class QueueDetailTraces extends Card
{
    use ScopesToQueue;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->queue !== '') {
            try {
                $results = $this->traces()->search(
                    '{ '.$this->traceScope($this->queueTraceScope()).' }',
                    $start, $end, limit: 25,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-traces';

        return view($view, [
            'results' => $results,
            'error' => $error,
            'title' => 'Recent runs',
            'subtitle' => 'Traces for this queue — click a row for the waterfall',
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.page', array_filter(['page' => 'traces', 'trace' => $traceId]));
    }
}
```

A trace row links to the `traces` page with `?trace=…`, which is where the
progressive drill bottoms out: the waterfall view.

## 3. Register the hidden page

`TelemetryUi::page()` (see `src/TelemetryUiManager.php`) has a `hidden` flag:

```php
public function page(
    string $slug,
    string $label,
    ?string $group = null,
    ?string $icon = null,
    ?string $detectMetric = null,
    bool $hidden = false,
): self
```

`hidden: true` registers a page that is **routable and rendered** — the
catch-all route resolves its slug like any other and renders its cards — but
`visiblePages()` filters it out of the sidebar and command palette. It's a page
you only ever reach by drilling into a row, not by browsing the nav. (The
built-ins register `job-detail`, `request-detail`, `exception-detail`,
`outgoing-detail` exactly this way.)

Wire it up in a service provider's `boot()`, cards in render order:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;
use App\Telemetry\Detail;

public function boot(): void
{
    if (class_exists(TelemetryUi::class)) {
        TelemetryUi::page('queue-detail', 'Queue', hidden: true);

        TelemetryUi::card(Detail\QueueDetailHeader::class, page: 'queue-detail');
        TelemetryUi::card(Detail\QueueDetailOutcomes::class, page: 'queue-detail');
        TelemetryUi::card(Detail\QueueDetailTraces::class, page: 'queue-detail');
    }
}
```

No route to register — `/telemetry-ui/queue-detail?queue=emails` works the
moment the page is registered.

## 4. Link a row into it

The overview table card owns the link. Add a `detailUrl()` method that builds
the hidden page's URL with the entity as a query param — mirror
`JobsTable::detailUrl()`:

```php
public function detailUrl(string $queue): string
{
    return route('telemetry-ui.page', array_filter([
        'page' => 'queue-detail',
        'queue' => $queue,
        'period' => $this->period,
        'service' => $this->service,
        'env' => $this->environment,
    ]));
}
```

`array_filter` drops empty params so the URL stays clean when nothing is
scoped. Then in the table's Blade, make the row's primary cell a link (the
built-in `jobs-table.blade.php` also sets `data-row-href` so the whole row is
clickable):

```blade
<tr data-row-href="{{ $this->detailUrl($row['queue']) }}">
    <td class="is-primary">
        <a href="{{ $this->detailUrl($row['queue']) }}" title="Open queue detail">
            {{ $row['queue'] }}
        </a>
    </td>
    {{-- …other cells… --}}
</tr>
```

That closes the loop: overview table → row click → scoped detail page →
entity's own numbers → its traces → the waterfall.

## Conventions

- Scope through `scopeMatchers()` / `metric()` / `traceScope()` /
  `logSelector()` — never build matchers by hand, so tenancy, global scope and
  escaping all keep working.
- **Escape every label value** with `addcslashes($value, '"\\')` (or
  `escapeLabelValue()`); URL params are untrusted input.
- Leave overview cards you intend to drill into un-`final` so detail cards can
  subclass them; make the leaf detail cards `final`.
- Skip trace/expensive queries when the entity prop is empty — an unscoped
  visit shouldn't fetch the whole backend.
- Carry `period`, `service` and `env` through every link (in and back out) via
  `array_filter([...])` so scope survives the drill.
- Register detail pages with `hidden: true`; catch `SourceException` per card
  so one broken backend never takes the page down.
