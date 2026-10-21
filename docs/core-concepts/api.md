---
title: JSON API
description: Endpoint reference for the v2 JSON API — scope params, filters, gates and the error format
weight: 24
---

# JSON API

The SPA is one consumer of a versioned JSON API mounted under
`{path}/api/v2` (default `/telemetry-ui/api/v2`). It only talks to the backend
over this API, so anything the dashboard shows you can also read yourself.

The API is **same-origin and session-authenticated**: it runs behind the
`telemetry-ui.middleware` stack (default `web`), the `viewTelemetryUi` gate and
the `telemetry-ui.throttle` rate limit (default `600,1`). There is no token
mode. `POST` requests need the CSRF token (`X-CSRF-TOKEN`); the SPA shell hands
it to the client in its bootstrap block.

The shapes are the contract the SPA is written against and are typed in
`resources/app/src/api/types.ts`. Within `v2` they only change additively.

## Scope parameters

Every read endpoint accepts:

| Param | Example | Meaning |
| --- | --- | --- |
| `period` | `1h`, `24h`, `7d` | Preset window. |
| `from`, `to` | `1735686000`, `now-2h` | Absolute or relative range; wins over `period` when both are valid and `from < to`. |
| `service` | `checkout` | Service scope. `service=` (empty) means all services: every service for metrics and traces, and the services in the scope switcher for logs (see `fleet.scopes_logs`). |
| `env` | `production` | Environment scope. `env=` means all. |
| `where[]` | `http.route=/checkout` | A filter, `key<op>value`, op one of `= != =~ !~ > >= < <=`. Repeatable, ANDed. |
| `q` | `timeout` | Free text (Explore). |
| `groupBy` | `billing.customer_id` | Group-by key (Explore). |

A parameter the URL does not state falls back to the reader's remembered
[view state](../extension-points/view-state.md). The service/environment scope
is always forced into the viewer's tenancy lock
([authorization](authorization.md#tenancy-lock-a-viewer-to-services--environments)),
so a hand-edited `?service=` can never widen it. Any other scalar query
parameter is passed to panels as a param (`?route=`, `?job=`).

See [dimensions & Explore](dimensions-and-explore.md#the-filter-syntax) for how
filters are applied per signal.

## Endpoints

| Method | Path | Returns | Gate |
| --- | --- | --- | --- |
| GET | `/bootstrap` | App config: brand, nav (detection- and gate-filtered), pages, Explore signals, entity types, dimensions, navLinks, connections, scope options + locks, remembered state, periods, refresh intervals, abilities, capabilities, `traces.groupThreshold` (the condensed waterfall's run length), `monitors` (`services` the viewer may monitor, `page`), user. | master |
| POST | `/view-state` | Reports the reader's window/scope (`period`, `from`, `to`, `service`, `env`, `refresh`); returns `{state}`. Sets the cookie and fires `ViewStateChanged` only when it changed. | master |
| GET | `/pages/{page}` | `{page, label, group, panels: [{id, span}]}`. 404 for an unknown page, or a detected page with no data in scope. | page |
| GET | `/panels/{panel}` | `{id, span, kind, …}` — the panel payload. Extra params scope detail panels. | any page the panel is on |
| GET | `/explore/{signal}` | `signal` ∈ `requests`, `traces`, `logs`, `errors`. `{signal, rows, stats, series, heatmap, groupBy, groups, sample, range, where}`. Params `q`, `groupBy`, `limit` (default 500 — 200 for `traces`, which can match many spans per trace — max 2000). | page covering the signal |
| | | The explore payload also carries `query`: the compiled TraceQL/LogQL for that exact view (`{language, text}`), or null when it can't be rendered. | |
| GET | `/facets/{signal}` | `{signal, facets: [{key, label, group, custom, values: [{value, count}]}], exact, sample}`. Params `keys[]` (default: the signal's built-in dimensions plus every declared one; at most 60) and `limit` (the sample the counts come from when they aren't exact). | page covering the signal |
| GET | `/entities/{type}` | Entity index: `{entity, signal, unit, values, stats, sample}`. `unit` says whether a value's count is requests or spans. | `requests` |
| GET | `/entities/{type}/story?value=` | One value's story: `{entity, signal, where, red, series, heatmap, statusMix, insights, breakdowns, slowest, failing, recent, errors, deploys, raw, panels, sample, range}`. 422 without `value`. | `requests` |
| GET | `/traces/{traceId}` | `{traceId, root, durationMs, error, spanCount, services, waterfall, chain, identities, context, profile, report, logs, logsMatch, exceptions, dimensionLinks}`. `exceptions` are the exception records for the trace and `logsMatch` says whether its logs were matched by trace id or by a time window. A trace whose services fall outside the viewer's scope lock (services, or environments) answers 404. Correlation parts are empty when their backend is down. `?at=` (epoch ms) is when the request started: the trace store is then searched around it only. `?without=context` leaves out `context`, `profile`, `logs`, `logsMatch` and `exceptions`, so only the trace store has to answer. | `traces` |
| GET | `/traces/{traceId}/context` | `{traceId, services, context, profile, logs, logsMatch, exceptions}` — the part `?without=context` leaves out, read from the metrics and logs backends. Same lock and `?at=` as above. | `traces` |
| GET | `/traces/{traceId}/markdown` | The trace as a Markdown snapshot: `{traceId, markdown, bytes, truncated, omitted}`, or the Markdown itself (`text/markdown`) with `?format=text`. Same lock and `?at=` as the trace. Stacktraces and source excerpts only when the viewer may also open `exceptions`. See [Markdown snapshots](#markdown-snapshots). | `traces` |
| GET | `/errors/{group}` | Error group: `{group, stats, occurrences, detail, request, suspect, releases, lookbackDays, canCreateIssue, tracker, draft, llm}`. `llm` is the group's Markdown snapshot (the same text `/errors/{group}/markdown` returns). | `exceptions` |
| GET | `/errors/{group}/markdown` | The error group as a Markdown snapshot: `{group, markdown, bytes, truncated, omitted}`, or `text/markdown` with `?format=text`. Scoped like `/errors/{group}`. | `exceptions` |
| GET | `/issues/{id}` | A tracker issue. 404 when no tracker is configured. | master |
| POST | `/issues` | Create an issue from `{title, body, labels[]}`; 201 with the issue. | `manageTelemetryUi` |
| GET | `/monitors` | `{services, monitors, errors, now, canManage}` — active laravel-telemetry directives on every service the viewer may monitor. See [Monitors](monitors.md). | `viewTelemetryUi` (`monitors`) |
| POST | `/monitors` | Place one from `{service, match, value, key?, minutes, profile, reason}`; 201 with `{monitor}`. Within the scope rule. | `manageTelemetryUi` |
| DELETE | `/monitors/{service}/{id}` | Stop one early. | `manageTelemetryUi` |
| GET | `/annotations` | `{annotations}` — deploy/change markers in range. | master |
| GET | `/dimensions/labels?key=&values[]=` | `{labels: {value: name}}`: display names from a dimension's resolver (max 200 values, cached per value, fail-open). See [Names instead of ids](dimensions-and-explore.md#names-instead-of-ids). | master |
| GET | `/stream/{signal}` | SSE live tail, `signal` ∈ `logs`, `requests`. | `logs` / `requests` |

"master" is `viewTelemetryUi` with no page; "page" is `viewTelemetryUi` with the
page slug as its second argument. The page covering each Explore signal:
`requests` → `requests`, `traces` → `traces`, `logs` → `logs`, `errors` →
`exceptions`.

### Explore and facets

`sample` tells you how much the numbers cover:

```json
"sample": {"size": 500, "limit": 500, "truncated": true, "exact": false, "groupsExact": true}
```

`rows` and `stats` always come from the bounded sample. `groups` (and facet
counts) are exact when the traces backend implements `AggregatesSpans`;
`groupsExact` and the facets response's `exact` flag (its `sample` is a row count, not the object the explore payload carries) say which you got.

### Markdown snapshots

"Copy as Markdown" in the trace drawer and on an error group copies a
snapshot built to paste into an AI agent, a pull request or a support ticket.
The same text is on the API, for `curl … | pbcopy`:

```bash
curl -s -b cookies.txt 'https://app.test/telemetry-ui/api/v2/traces/4bf92f3577b34da6a3ce929d0e0e4736/markdown?format=text'
```

A **trace** snapshot holds, in this order:

1. a title — root, service, status, duration, start, label, trace id and a
   link to the trace;
2. the flags from the trace diagnosis (the `trace_summary` MCP tool's),
   errors first;
3. time per category and the root's totals;
4. the exceptions, each with its file:line, group, the app frames of its
   stacktrace (framework frames left out) and the `exception.source` excerpt
   when the record carries one;
5. what led up to the first failing span: the last 10 spans that started
   before it;
6. the N+1, duplicate and slow query groups, each with its SQL, run count,
   time and the `file:line` that ran it;
7. the N+1 outgoing call groups (laravel-telemetry 3.6+), each with the
   call (`GET api.example.com/users/{id}`), an example URL, call count, time
   and the `file:line` that made it.

An **error group** snapshot has its type, message, location, fingerprint,
counts and first/last seen, the request that hit it, the suspect change, the
app frames, the source excerpt, the releases it was seen in and the
stacktrace, plus links to the newest occurrence's trace and that trace's
snapshot.

Each list has its own limit and says how much it left out. The whole snapshot
is capped at 12 KB. When something has to give, the sections at the end go
first, and a closing `> **Truncated**` line names them. The JSON form reports
the same thing in `truncated` and `omitted`.

A snapshot only uses data the backends already hold. SQL is the statement
text as the app recorded it: laravel-telemetry records the parameterised
statement, and the snapshot never reads or adds bindings. The endpoints use
the same gate, scope and scope lock as the views they copy, so a trace
outside the viewer's lock is a 404 here too.

### Live tail (SSE)

`GET /stream/logs` and `GET /stream/requests` return `text/event-stream`. The
server polls the backend every `telemetry-ui.stream.interval` seconds (default
2) for rows newer than the cursor and emits:

```
retry: 2000

id: 1758531234000000000
event: rows
data: {"rows":[…]}

: keepalive
```

A backend failure sends `event: error` with `{"type":"backend","message":…}`
and closes. Each connection lives for `telemetry-ui.stream.window` seconds
(default 25) and then ends; `EventSource` reconnects and resumes from
`Last-Event-ID`, so a long tail never pins a PHP worker. `?since=` (nanoseconds)
sets the start cursor, and `?once=1` emits one batch and closes — the SPA's
polling fallback uses it. Neither `stream.*` key is in the published config;
add them if you need other values.

## Errors

Every failure is typed, so a client can tell "backend down" from "not allowed"
from "empty":

```json
{"error": {"type": "backend", "message": "The traces backend is unavailable."}}
```

| `type` | Status | When |
| --- | --- | --- |
| `backend` | 502 | A backend query failed. The message is generic; full detail is logged server-side. |
| `forbidden` | 403 | The gate denied the request. |
| `invalid` | 422 | Bad input (missing entity value, invalid error-group id, empty issue title). |
| `not_found` | 404 | Unknown page, panel, entity type, trace or issue. |

A panel that caught its own backend error still answers 200, with an `error`
key in its payload, so the rest of the page renders.

## The SPA shell

Every other path under `{path}` (not `api/` or `build/`) returns the SPA shell:
a small HTML document with a JSON bootstrap block
(`<script type="application/json" id="telemetry-ui-boot">`) holding `base`,
`api`, `assets`, `csrf` and `brand`. Hashed JS/CSS chunks are served from
`{path}/build/*` without the gate or throttle, since they are immutable static
files.
