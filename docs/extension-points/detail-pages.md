---
title: Custom detail pages
description: Scope panels to one entity and show them on its entity page
weight: 4
---

# Custom detail pages

Clicking a row — a route, a job, a host — opens that entity's page. In v2 the
entity page is generated from a [dimension](../core-concepts/dimensions-and-explore.md):
its **Story** tab (RED, trend, breakdowns, correlated errors, example traces)
needs no code at all. What you add here is the **Metrics** tab: panels that
show the entity's own metrics, scoped to one value.

Mechanically that is four things:

1. **A scope** — a `#[Param]` property (`?tenant=…`) plus a `scopeMatchers()`
   override that narrows every metric query on the panel to that entity.
2. **Detail panels** — mostly the *overview* panels you already ship,
   subclassed so the scope applies. Reuse, not rewrite.
3. **A hidden page** holding those panels, registered with `hidden: true`.
4. **An entity mapping** — `TelemetryUi::entityPage()` tells the entity page to
   run that page's panels with the entity value as the param.

This walkthrough builds one for a hypothetical "tenant" entity: the app stamps
`app.tenant` on its spans and a `tenant` label on its job metrics.

## 1. The scope

Every `Panel` has a `scopeMatchers()` hook. It returns extra PromQL label
matchers — a string like `label="value"` — that `metric()` appends to *every*
metric reference on the panel, alongside the global service/environment scope.
The default returns `''` (unscoped).

The built-ins package this as a small trait per entity — `ScopesToJob`,
`ScopesToRoute`, `ScopesToHost`, `ScopesToQueue`, … in
`src/Panels/Builtin/Detail/`. `ScopesToJob` in full, the shape to copy:

```php
namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

trait ScopesToJob
{
    #[Param('job')]
    public string $job = '';

    protected function scopeMatchers(): string
    {
        return $this->job === '' ? '' : 'job_name="'.addcslashes($this->job, '"\\').'"';
    }

    /**
     * @return list<TraceCondition>
     */
    protected function jobTraceConditions(): array
    {
        return [TraceCondition::eq('span.laravel.job.class', $this->job)];
    }
}
```

- `#[Param('job')]` binds the entity from `?job=`. The entity page passes it
  when it fetches the panel; `/p/job-detail?job=…` works too.
- `scopeMatchers()` returns the PromQL matcher — empty when unscoped, so the
  same panel degrades to "all jobs".
- The `*TraceConditions()` helper returns the TraceQL condition for the same
  entity (label names differ between Prometheus and Tempo), passed to
  `traceQuery()`. `TraceCondition` values are escaped by the compiler.

For the tenant entity:

```php
namespace App\Telemetry\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

trait ScopesToTenant
{
    #[Param('tenant')]
    public string $tenant = '';

    protected function scopeMatchers(): string
    {
        return $this->tenant === '' ? '' : 'tenant="'.addcslashes($this->tenant, '"\\').'"';
    }

    /**
     * @return list<TraceCondition>
     */
    protected function tenantTraceConditions(): array
    {
        return [TraceCondition::eq('span.app.tenant', $this->tenant)];
    }
}
```

### Escaping is not optional

Note the `addcslashes($value, '"\\')` in `scopeMatchers()`. The entity value
comes straight from a query string — attacker-controllable — and lands inside a
quoted PromQL matcher. A raw `"` or `\` would break out of the string and let
the query be rewritten.

**Never string-concatenate a raw label value into a query.** Every value that
crosses into a hand-built matcher goes through `addcslashes($value, '"\\')` (or
`$this->escapeLabelValue(...)`). Prefer the IR (`TraceCondition::eq()`,
`metric()`), which escapes for you.

## 2. Detail panels — subclass the overview panels

A panel written for the *overview* page works unchanged on the *detail* page
once `scopeMatchers()` is non-empty, because every query goes through
`metric()`. So most built-in detail panels are a subclass plus the trait.
`JobDetailOutcomes` is the whole file:

```php
namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;

final class JobDetailOutcomes extends JobsOverview
{
    use ScopesToJob;
}
```

For this the overview panel must be **subclassable** — `class JobsOverview
extends Panel`, not `final`. Overview panels that detail pages reuse are left
un-`final`; leaf detail panels are `final`. The tenant version:

```php
namespace App\Telemetry\Detail;

use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;

final class TenantJobOutcomes extends JobsOverview
{
    use ScopesToTenant;
}
```

### A header panel

The one panel you write by hand is the header — the entity name and its
headline stats. Model it on `JobDetailHeader`:

```php
namespace App\Telemetry\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

final class TenantHeader extends Panel
{
    use ScopesToTenant;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $p = $this->promDuration();
        $error = null;
        $processed = $failed = 0.0;

        try {
            $processed = $this->total($this->metric('queue_jobs_processed_total')->increase($p)->sumBy());
            $failed = $this->total($this->metric('queue_jobs_failed_total')->increase($p)->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::header($this->tenant === '' ? '(all tenants)' : $this->tenant, 'Tenant', [
            ['label' => 'Jobs processed', 'value' => Format::count($processed), 'tone' => null],
            ['label' => 'Jobs failed', 'value' => Format::count($failed), 'tone' => $failed > 0 ? 'danger' : 'dim'],
        ], [
            'back' => [...Ui::page('jobs'), 'label' => '← All jobs'],
            'error' => $error,
            'span' => 2,
        ]);
    }
}
```

The scope (period, range, service, env) lives in the SPA's URL and travels
with every link, so links don't carry it.

### A traces panel

List the entity's own traces. Copy `JobDetailTraces`: search with
`traceQuery()` (the global scope) plus your conditions, and skip the query when
the entity is empty so an unscoped visit doesn't fetch everything.

```php
final class TenantTraces extends Panel
{
    use ScopesToTenant;

    public function data(): array
    {
        [$start, $end] = $this->range();
        $results = [];
        $error = null;

        if ($this->tenant !== '') {
            try {
                $results = $this->traces()->search($this->traceQuery(...$this->tenantTraceConditions()), $start, $end, limit: 25);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $rows = array_map(static fn ($s): array => [
            '_link' => Ui::trace($s->traceId),
            'trace' => Ui::cell($s->rootTraceName !== '' ? $s->rootTraceName : '(unnamed)'),
            'duration' => Ui::cell(Format::ms($s->durationMs), ['raw' => $s->durationMs, 'mono' => true]),
        ], $results);

        return Ui::table('Recent traces', [Ui::col('trace', 'Trace'), Ui::num('duration', 'Duration')], $rows, [
            'error' => $error,
            'empty' => 'No traces for this tenant in this period.',
        ]);
    }
}
```

`Ui::trace()` opens the waterfall in the drawer. (For a trace list the entity
page's Story tab already shows failing and slowest traces, so this panel is
optional.)

## 3. Register the page, the dimension and the mapping

```php
use App\Telemetry\Detail;
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    if (! class_exists(TelemetryUi::class)) {
        return;
    }

    // The entity: app.tenant values open /entity/tenant?value=…
    TelemetryUi::dimension('app.tenant', label: 'Tenant', group: 'App', entity: 'tenant');

    // The panels, on a hidden page (routable, not in the sidebar or palette).
    TelemetryUi::page('tenant-detail', 'Tenant', hidden: true);
    TelemetryUi::panel(Detail\TenantHeader::class, page: 'tenant-detail');
    TelemetryUi::panel(Detail\TenantJobOutcomes::class, page: 'tenant-detail');
    TelemetryUi::panel(Detail\TenantTraces::class, page: 'tenant-detail');

    // The entity page's Metrics tab runs tenant-detail's panels with ?tenant={value}.
    TelemetryUi::entityPage('tenant', 'tenant-detail', 'tenant');
}
```

No routes to register. `/entity/tenant?value=acme` shows the story with your
panels in the Metrics tab; `/p/tenant-detail?tenant=acme` shows the panels on
their own.

The built-ins map `route`, `job`, `queue`, `host`, `query`, `outgoing` and
`path` this way (`TelemetryUiManager::$entityPages`); calling `entityPage()`
with a built-in slug replaces its mapping.

## 4. Link rows into it

An overview table links each row with `Ui::entity()`:

```php
$rows[] = [
    '_link' => Ui::entity('tenant', $tenant),          // whole row
    'tenant' => Ui::cell($tenant, [
        'link' => Ui::entity('tenant', $tenant),       // the primary cell
        'dim' => ['key' => 'app.tenant', 'value' => $tenant], // chip menu: filter, group, open
    ]),
    // …other cells…
];
```

That closes the loop: overview table → row click → entity page (story +
Metrics tab) → a trace → the waterfall.

## Conventions

- Scope through `scopeMatchers()` / `metric()` / `traceQuery()` /
  `logSelector()` — never build matchers by hand, so tenancy, global scope and
  escaping keep working.
- **Escape every hand-built label value** with `addcslashes($value, '"\\')` (or
  `escapeLabelValue()`); query params are untrusted input.
- Leave overview panels you intend to reuse un-`final`; make leaf detail panels
  `final`.
- Skip trace and other expensive queries when the entity param is empty.
- Register detail pages with `hidden: true`; catch `SourceException` in every
  panel so one broken backend never takes the page down.
