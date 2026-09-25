---
title: Dimensions & Explore
description: Declared dimensions, the filter syntax, facets, group-by and entity pages
weight: 3
---

# Dimensions & Explore

A span carries attributes: `http.route`, `user.id`, `client.address`, and
whatever your app adds (`billing.customer_id`). v2 lets you filter, group and
drill by any of them. A **dimension** is an attribute the UI promotes: it gets
a label, a place in the facet panel, a group-by entry, clickable chips and an
entity page.

## Declaring dimensions

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::dimension('billing.customer_id',
        label: 'Customer',
        group: 'Billing',
        link: fn (string $id) => route('customers.show', $id),
    );

    TelemetryUi::dimension('billing.campaign_id',
        label: 'Campaign',
        group: 'Billing',
        link: 'https://admin.example.com/campaigns/{value}',
    );
}
```

| Argument | Default | Meaning |
| --- | --- | --- |
| `key` | — | The attribute key as emitted. |
| `label` | the key | Shown in facets, chips and the entity page. |
| `group` | `null` | Facet-panel group heading. |
| `link` | `null` | A link *out* to your app: a closure `fn (string $value): ?string`, or a URL template with `{value}` (URL-encoded). A closure that throws yields no link. |
| `entity` | the key | The entity slug in URLs (`/entity/customer?value=…` with `entity: 'customer'`). |
| `scope` | `'span'` | Where the attribute lives: `span`, `resource` or `intrinsic` (TraceQL `status`, `name`, `duration`, `kind`). Decides the TraceQL field (`span.x`, `.x`, or the intrinsic). |
| `signals` | `['requests', 'traces']` | Which Explore signals show it as a facet by default. |
| `format` | `null` | A presentation hint for the SPA: `string`, `number`, `status` (built-ins also use `sql`). |
| `plural` | label + `s` | Used on the entity index ("Customers"). |
| `spanKind` | `null` | Restrict the dimension to one span kind (`client`, `server`, …). Applied when grouping by it, on its entity pages, and on `=` filters (so a drill-down keeps the population the group described). The built-in `server.address` uses it: on a CLIENT span the value is the remote peer, on a SERVER span it is the local host that received the request, so without it every inbound request is listed as an outgoing dependency. |

Registration is data-only; it costs nothing at boot. Re-declaring a key merges
with what is there, so you can add a link to a built-in:

```php
TelemetryUi::dimension('user.id', link: fn ($id) => route('admin.users.show', $id));
```

`TelemetryUi::removeDimension($key)` drops one; `TelemetryUi::dimensions()`
returns the registry.

Undeclared attributes stay filterable by their raw key. They just don't get a
label, a facet or an entity page.

### A dimension that lives inside another attribute

A value doesn't have to be its own attribute. When a routing layer encodes it
in a standard one — `livewire:{component}`, `portal:{screen}` — declare where
to read it from:

```php
TelemetryUi::dimension('portal.screen', label: 'Screen', group: 'Billing',
    from: 'http.route', pattern: 'portal:{value}');
```

The pattern is a template, not a regex: the literal parts around `{value}` are
what the emitter writes. That keeps both directions exact, so filters still
compile to a query the backend answers —
`portal.screen = "checkout"` becomes `http.route = "portal:checkout"`,
`portal.screen != ""` ("any screen") and `=~` become one anchored regex on the
source. Nothing is filtered after the fact.

The dimension is otherwise ordinary: chips on rows, a filter key with value
typeahead, group-by, and its own entity page. The one difference is counting —
no backend can group by a value it doesn't store, so **facet counts for a
derived dimension come from the sample** and the response reports
`exact: false`.

For the common case — a layer that names requests and deserves its own page —
`TelemetryUi::routeFamily()` does this plus the page in one call. See
[developer integrations](../extension-points/index.md#a-routing-layer-as-its-own-area).

### Names instead of ids

Traces carry ids (`user.id = 20`, `billing.customer_id = 8655`). Give a
dimension a resolver and the dashboard shows the name next to the id
everywhere a value appears: chips on rows, facets, filter chips, group-by
tables, entity lists and the entity page title ("Kaylin Jenkins · 20").

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

// An Eloquent model and the attribute to show (matched on the model's key):
TelemetryUi::resolve('user.id', \App\Models\User::class, 'name');

// Match on another column, or build the name yourself:
TelemetryUi::resolve('billing.customer_id', Customer::class, fn (Customer $c) => $c->company, column: 'external_id');

// Any lookup: receive a batch of values, return [value => name]:
TelemetryUi::resolve('tenant.id', fn (array $ids) => Tenant::whereIn('uuid', $ids)->pluck('name', 'uuid'));

// Or declare it together with the dimension:
TelemetryUi::dimension('billing.campaign_id', label: 'Campaign', resolve: fn (array $ids) => Campaign::findMany($ids)->pluck('title', 'id'));
```

`resolve()` works on built-in dimensions too and changes nothing else about
them. Lookups go through `GET {path}/api/v2/dimensions/labels?key=…&values[]=…`:
the SPA batches every id on screen into one request per dimension (at most
200 values). The API caches each name, and each unknown id, for
`telemetry-ui.dimensions.label_ttl` seconds (default 300, `0` disables). A
resolver that throws yields no names rather than an error, so the raw id
still renders. The endpoint sits behind the dashboard gate like every other
API route, so the names are only as visible as the dashboard itself.

### Built-in dimensions

Defined in `src/Dimensions/Dimensions.php`, matching what
`cboxdk/laravel-telemetry` v2 emits:

| Key | Label | Entity slug |
| --- | --- | --- |
| `status` (intrinsic) | Span status | — |
| `http.response.status_code` | Status code | — |
| `http.request.method` | Method | — |
| `http.route` | Route | `route` |
| `url.path` | Path | `path` |
| `user.id` | User | `user` |
| `client.address` | Client IP | `ip` |
| `geo.country.iso_code` | Country | — |
| `device.type` | Device | — |
| `service.name` (resource) | Service | `service` |
| `deployment.environment.name` (resource) | Environment | — |
| `host.name` (resource) | Host | `host` |
| `deployment.id` (resource) | Deploy | — |
| `db.query.text` | Query | `query` |
| `db.system.name` | DB system | — |
| `view.name` | View | `view` |
| `laravel.job.class` | Job | `job` |
| `messaging.destination.name` | Queue | `queue` |
| `server.address` | Outgoing host | `outgoing` |
| `laravel.command` | Command | `command` |
| `name` (intrinsic) | Span name | — |

Built-ins with an entity slug, and every dimension you declare, get an entity
page.

## Where dimensions show up

- **Facet panel** (Explore, left): top values with counts, grouped by `group`.
- **Filter bar** (Explore, top): the active filters as removable chips.
- **Group-by** menu: break any Explore view down by the dimension.
- **Chips** on every trace, request row and entity page: click to filter,
  exclude, group by, open the entity page, or follow the link out.

## The filter syntax

Filters travel in the URL as `where[]=key<op>value`. The URL is the query, so
every view is shareable and back/forward works.

| Operator | Meaning |
| --- | --- |
| `=` / `!=` | Equals / not equals. |
| `=~` / `!~` | Regex match / no match. |
| `>` `>=` `<` `<=` | Numeric comparison. |

```
/telemetry-ui/explore/requests?where[]=billing.customer_id=8655&where[]=http.response.status_code>=500
```

The operator is the first operator token after the key; keys never contain
`= ! < > ~`, values may contain anything. Filters are ANDed and applied by the
backend:

- **requests / traces** — compiled to TraceQL through the query IR
  (`{ span.billing.customer_id = "8655" }`).
- **logs** — compiled to LogQL label filters (dots in keys become
  underscores). `level=error` matches either `level` or `detected_level`. Only
  `= != =~ !~` apply to logs.
- **errors** — label filters on the exception records in Loki, and TraceQL
  conditions on browser error spans.

`q` is free text: a substring of the span name on requests and traces, a line
filter on logs, and a substring of the exception type or message on errors.

## Explore

`/explore/{signal}` with signal `requests` (server spans), `traces`, `logs` or
`errors`. It shows headline stats (RED), a distribution over time, a
time × latency heatmap, an optional group-by table and a virtualised,
newest-first result list. Clicking a row opens the trace (or error group) in
the drawer.

Results are a bounded sample: `limit` defaults to 500 (200 for `traces`, where one search can match many spans per trace) and is capped at 2000.
The response's `sample` block says how big the sample was, whether it was
truncated, whether its counts are exact, and — as `readSideFiltered` — whether
a filter the backend could not evaluate was applied after fetching. See the [API reference](api.md#explore-and-facets).

### Working the view

- **Filters**: every chip is a `where[]` in the URL. `+ filter` suggests keys
  from the registry, and once you type `key=` it suggests the values actually
  present in this view, with counts and resolved names. Click a chip's
  operator to invert it, ✕ to drop it, backspace on the empty box to pop the
  last one.
- **The query behind it**: the chip next to the stats shows the compiled
  TraceQL / LogQL for this exact view, with copy and copy-as-curl. The same
  string is on the API response as `query`.
- **Saved views** name the page + filters + window and come back from the
  button or ⌘K. They live in the viewer's browser (localStorage), so they are
  a personal convenience, not shared config.
- **Keyboard**: `?` lists everything. `/` focuses search, `[` / `]` step the
  window by its own length, `n` returns to now, `r` refetches, `j` / `k` walk
  the results — with a trace open they step it in place, so a page of failures
  triages without the mouse.
- **Empty is actionable**: no matches offers back-to-now, a wider window and
  clearing the filters, whichever applies.

### Facets and group-by: exact or sampled

Whether counts are exact depends on the traces backend:

- **Exact** when the driver implements `Contracts\AggregatesSpans` (the
  ClickHouse store, telemetryd). Facets and group-by are computed over every
  matching span.
- **Sampled** otherwise (plain Tempo). Counts are folded over the search sample
  and the UI labels them as a sample of N.

The facets response carries `exact` and `sample`; the Explore response carries
`sample.groupsExact`. `GET /api/v2/bootstrap` reports
`capabilities.exactAggregation` so the SPA knows up front.

## Entity pages

An entity is a dimension value: a route, a query, a job, a host, a customer.
One template serves every type.

- `/entities/{type}` — the index: every value of the dimension in scope, with
  requests, error rate and latency.
- `/entity/{type}?value=…` — one value's story. The value travels as a query
  parameter because routes and SQL contain slashes.

`{type}` is an entity slug (`route`, `query`, `host`) or any declared
dimension key (`billing.customer_id`).

The story page has three tabs:

1. **Story** — plain-language insights (where failures concentrate, what
   changed around a deploy), RED, a trend with deploy markers, breakdowns by
   other dimensions with failure lift, the status-code mix, correlated
   exception groups, and the failing and slowest example traces.
2. **Metrics** — the v1 detail panels for that entity type, scoped by one
   param. Built-in mappings:

   | Entity | Page | Param |
   | --- | --- | --- |
   | `route` | `request-detail` | `route` |
   | `job` | `job-detail` | `job` |
   | `queue` | `queue-detail` | `queue` |
   | `host` | `host-detail` | `host` |
   | `query` | `query-detail` | `dbq` |
   | `outgoing` | `outgoing-detail` | `host` |
   | `path` | `page-detail` | `path` |

   The built-in `url.path` dimension uses the `path` slug, so a path's
   entity page runs the page-detail (analytics / RUM) panels.

   Attach your own with `TelemetryUi::entityPage('customer', 'customer-detail',
   'customer')`: the page's panels run with `?customer={value}`. See
   [custom detail pages](../extension-points/detail-pages.md).
3. **Raw** — the attributes of a representative span, last.

Stories are built from a sample of up to 500 matching spans, from server spans
when the dimension lives there and from any span otherwise. Entity endpoints use
the `requests` page's gate.
