---
title: Dimensions & Explore
description: Declared dimensions, the filter syntax, facets, group-by and entity pages
weight: 3
---

# Dimensions & Explore

A span carries attributes: `http.route`, `user.id`, `client.address`, and
whatever your app adds (`hubhus.customer_id`). v2 lets you filter, group and
drill by any of them. A **dimension** is an attribute the UI promotes: it gets
a label, a place in the facet panel, a group-by entry, clickable chips and an
entity page.

## Declaring dimensions

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::dimension('hubhus.customer_id',
        label: 'Customer',
        group: 'Hubhus',
        link: fn (string $id) => route('customers.show', $id),
    );

    TelemetryUi::dimension('hubhus.campaign_id',
        label: 'Campaign',
        group: 'Hubhus',
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

Registration is data-only; it costs nothing at boot. Re-declaring a key merges
with what is there, so you can add a link to a built-in:

```php
TelemetryUi::dimension('user.id', link: fn ($id) => route('admin.users.show', $id));
```

`TelemetryUi::removeDimension($key)` drops one; `TelemetryUi::dimensions()`
returns the registry.

Undeclared attributes stay filterable by their raw key. They just don't get a
label, a facet or an entity page.

### Built-in dimensions

Defined in `src/Dimensions/Dimensions.php`, matching what
`cboxdk/laravel-telemetry` v2 emits:

| Key | Label | Entity slug |
| --- | --- | --- |
| `status` (intrinsic) | Span status | — |
| `http.response.status_code` | Status code | — |
| `http.request.method` | Method | — |
| `http.route` | Route | `route` |
| `url.path` | Path | — |
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
/telemetry-ui/explore/requests?where[]=hubhus.customer_id=8655&where[]=http.response.status_code>=500
```

The operator is the first operator token after the key; keys never contain
`= ! < > ~`, values may contain anything. Filters are ANDed and applied by the
backend:

- **requests / traces** — compiled to TraceQL through the query IR
  (`{ span.hubhus.customer_id = "8655" }`).
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

Results are a bounded sample: `limit` defaults to 500 and is capped at 2000.
The response's `sample` block says how big the sample was and whether it was
truncated. See the [API reference](api.md#explore-and-facets).

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
dimension key (`hubhus.customer_id`).

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
