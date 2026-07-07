---
title: Web analytics & RUM
description: Turn on visit analytics and real-user monitoring, and what each dashboard page shows
weight: 1
---

# Web analytics & real-user monitoring

Because `cboxdk/laravel-telemetry` instruments **both** the server *and* the
browser and stitches them with one `traceparent`, this package can show visit
analytics that most tools can't — every page view is one row carrying behaviour
(referrer, device, engagement), performance (Web Vitals + backend timing),
errors, and real user identity, with drill-down to the exact trace.

Nothing here is on by default. It's an additive layer on top of the telemetry
you already collect, and every piece is a separate flag.

## Turn it on (in the emitter)

Analytics and RUM live in the emitting package. In your app's `.env`:

```dotenv
# Visit analytics — one unsampled analytics.page_view event per view.
TELEMETRY_ANALYTICS=true
TELEMETRY_ANALYTICS_SALT=change-me         # salts the cookieless daily session hash
TELEMETRY_ANALYTICS_UA=true                # parse User-Agent → device / browser / os
# TELEMETRY_ANALYTICS_GEO=true             # needs a GeoLite2 .mmdb (optional dep)
# TELEMETRY_ANALYTICS_GEO_DB=/path/to/GeoLite2-Country.mmdb

# Browser RUM — page-load timings, fetch spans, JS errors, SPA views, engagement.
TELEMETRY_INGEST_SPANS=true
```

Then drop the browser SDK into your layout's `<head>` — it renders the
`traceparent` meta tag and the zero-build script:

```blade
<head>
    …
    @telemetryBrowser
</head>
```

That's it. Server-side page views start flowing immediately; browser events
start as soon as a page with `@telemetryBrowser` is loaded.

### Privacy

Unique visitors are counted by `session.id` — a **cookieless, daily-rotating
hash** of `ip + user-agent + host + salt`. No cookie, no consent banner, and the
raw IP is never the grouping key (and needn't be stored). It rotates at
midnight, so uniques are per-day; cross-day retention is deliberately not
possible in this mode.

## Where the data goes

- `analytics.page_view` and `analytics.engagement` are emitted as **unsampled
  OTLP log records** (→ Loki). Unsampled matters: page views must never be
  undercounted, even when the full trace is tail-sampled away.
- Browser RUM spans (`document.load`, `fetch …`, `exception`) are **traces**
  (→ Tempo), tagged `browser=true`.

Point the emitter at your backend as usual — a local
[otel-lgtm](connect-via-grafana-proxy.md) for development, or a remote OTLP
endpoint:

```dotenv
TELEMETRY_OTLP_ENDPOINT=https://your-collector.example.com
TELEMETRY_OTLP_TOKEN=<bearer-token>        # sent as Authorization: Bearer …
```

## What the dashboard shows

Point Telemetry UI at the same backend and three surfaces light up.

### Analytics (Monitoring → Analytics)

- **Overview** — page views, **unique visitors**, views-per-visit, **bounce
  rate** (single-page-view sessions) and **average engagement time**, above a
  page-views trend chart (with deploy annotations).
- **Top pages** — most-viewed pages with distinct visitors each.
- **Sources & audience** — referrers, and (with geo / UA parsing on) countries
  and devices.

### Frontend (Monitoring → Frontend)

- **Page performance** — real-user navigation timings (loads, avg load, TTFB,
  DOM-interactive) from the `document.load` spans, per page.
- **Failed browser requests** — fetch/XHR calls that 5xx'd or errored; each row
  opens a representative trace, where a same-origin failure continues into the
  backend span that caused it.

### Errors (Activity → Exceptions)

The **unified errors list** groups frontend and backend errors together by
`exception.group` (the same fingerprint both sides stamp), so a JS `TypeError`
and a PHP exception that are the same issue collapse into one row.

## Accuracy & scale

The dashboard reads and aggregates these events from Loki/Tempo. That is:

- **Exact for low-traffic sites** — the query covers every event.
- **A bounded recent sample at scale** — the UI caps how many events it scans.

### The cardinality bomb — read before enabling every dimension

Loki (and Prometheus) index by **stream labels**, and every *distinct
combination of label values* is a separate stream. This LGTM promotes each
analytics attribute to a stream label, so a dimension's cost is its
**cardinality** — how many distinct values it takes. Low-cardinality dimensions
are cheap; unbounded ones (a per-visitor id, a full URL, a city) multiply the
stream count and make Loki slow and memory-hungry.

Cardinality is created **at ingest, in the emitter** — not by the UI. Hiding a
column in `telemetry-ui.analytics.dimensions` stops the dashboard rendering it,
but the label was already written. So there are two control layers:

| Layer | Controls | Where |
|---|---|---|
| **Capture** (cardinality) | whether the attribute is written at all | emitter — `telemetry.analytics.*` |
| **Surface** (cosmetic) | whether the dashboard shows it | UI — `telemetry-ui.analytics.dimensions` |

Per-dimension cardinality and the capture flag that gates it:

| Dimension | Cardinality | Emitter flag |
|---|---|---|
| **channel** (Direct/Organic/Social/Paid/…) | **none** — derived at read time from the referrer (+ UTM), never stored | — |
| source, device, os, browser, country | **low** (bounded set) | `user_agent`, `geo` |
| referrer (domain), region | **medium** | always on / `geo` |
| **url_path** | **high** (one per page) | always on — inherent |
| **utm_\*** | **high** (one per campaign string) | `utm` |
| **city** | **high** | `geo` (Enterprise CF / MaxMind city db) |
| **session_id** | **very high** (one per visitor-day) | inherent to uniques |

Rule of thumb on the LGTM stack: keep the **low/medium** dimensions on freely;
turn the **high**-cardinality ones (`city`, `utm_*`) on only at low traffic or
behind a short retention. `url_path` and `session_id` are inherent to
"top pages" and "unique visitors", so they set a practical ceiling — which is
exactly why the next step is a columnar store.

### At real volume: ClickHouse

For real traffic, analytics wants a columnar store, not the LGTM stack: point
the emitter's analytics stream (already tagged `telemetry.stream=analytics` for
exactly this) at a **ClickHouse** sink — exact `uniq`/HLL, funnels, every
dimension without a cardinality penalty, long retention. The same dashboard
cards read it. LGTM stays perfect for low-traffic sites and for validating the
pipeline before the sink exists.
