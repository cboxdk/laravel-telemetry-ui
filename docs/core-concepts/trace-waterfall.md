---
title: Trace waterfall
description: The condensed and full layouts, how spans are grouped, paging through a group, and what a 5,000-span trace costs to render
weight: 29
---

# Trace waterfall

The trace drawer's *Waterfall* tab draws every span on the trace's timeline.
A request with an N+1 of 300 queries or a loop of 500 cache reads makes that
a wall of identical rows, so the waterfall has two layouts:

- **Condensed** (the default). A run of identical sibling spans becomes one
  row.
- **Full**. One row per span, as before.

Switch with the *Condensed / Full* control above the waterfall. The choice is
remembered per viewer in the browser's local storage. A private window, or a
browser that blocks storage, gets the default and the switch still works for
the session.

## What gets grouped

Spans fold into a group when they are **consecutive siblings** (same parent,
nothing in between), **leaves** (no children of their own), and **the same**:

| Spans | "The same" means |
|---|---|
| Queries | Same name and the same `laravel.db.query.shape`, the hash of the normalised SQL that laravel-telemetry 3.2 stamps on every query span. Traces from before 3.2 fall back to identical `db.query.text`. |
| Cache operations | Same name, `cache.store`, `cache.operation` and `cache.key.group`. |
| Views | Same name and `view.name`. |
| Outgoing HTTP | Same name, `http.request.method` and `server.address`. |
| Redis | Same name, `db.operation.name` and `db.system.name`. |
| Jobs, queues | Same name and `laravel.job.class` / `messaging.destination.name`. |
| Anything else | Same name. |

Service and span kind are always part of the match, so a client span never
merges with the server span it caused.

A run needs at least `traces.waterfall.group_threshold` spans (default 3) to
fold. Below 2 turns grouping off. See the
[configuration reference](configuration.md).

A span that has children is never grouped. Its subtree stays visible, so
nothing nests out of sight inside a group.

## The group row

One row per group shows:

- the span name and the count (`×300`);
- **N+1** or **duplicate** when the occurrences carry
  `laravel.db.query.repeat`. laravel-telemetry 3.2 sets it from the
  threshold-crossing occurrence onward. N+1 wins when both appear;
- the `file:line` that ran them, from the first occurrence with `code.*`
  attributes (the emitter records these on the crossing span and on slow
  spans only);
- the shared SQL text or cache key group;
- a mini-timeline of where the occurrences fell inside their parent's time
  range, with failed occurrences in red;
- on the timeline track, the group's extent with one tick per occurrence,
  and the total (`Σ`) and worst (`max`) duration.

### Errors are never hidden

A group with failed occurrences turns red and shows **N failed**. Clicking
that badge opens the group on the first failure. From there, *Next failure*
(or the `e` key) steps through the rest.

## Paging through a group

Click a group to open it. It shows **occurrence 1 of N**, and that span's
attributes appear in the span detail panel, as for any other span. Step with:

| Key / control | Does |
|---|---|
| `←` / `→` or the arrow buttons | Previous / next occurrence |
| `Home` / `End` | First / last occurrence |
| `e` or *Next failure* | The next failed occurrence (wraps) |
| `Esc` | Close the group |

The pager takes keyboard focus when the group opens, so the arrow keys work
straight away. `↑` / `↓` still step between traces in Explore.

*Show all N* lists every occurrence inline, numbered, under the group.
Clicking one selects it and moves the pager there. *One at a time* goes back.

## Rendering cost

Long waterfalls are virtualised. Past 150 rows, in either layout and with a
group shown in full, only the rows on screen (plus a margin) are in the DOM.
They are measured against the drawer's own scroll, so there is no nested
scroll box. The virtualiser is `@tanstack/react-virtual`, which the app
already uses for Explore and the logs list. No dependency was added.

Measured with `npm run bench:waterfall` (a production build in headless
Chromium, Apple M-series, median of 5 runs). The trace has 5,000 spans: an
N+1 of 1,200 queries with one failure, 1,500 cache reads, 40 jobs with 30
queries each, and 1,057 renders of one partial. "→ paint" includes waiting up to two
frames (~33 ms) for the paint.

| | Rows in DOM | Mount → paint | Scroll frame p95 / max |
|---|---|---|---|
| Previous flat waterfall (every span) | 5,001 | 180 ms | 16.8 / 17.6 ms |
| Full, virtualised | 55 | 26 ms | 17.4 / 17.9 ms |
| Condensed (86 rows) | 87 | 28 ms | 17.5 / 18.3 ms |
| *CPU throttled 4×:* previous flat | 5,001 | 758 ms | 33.9 / 78.9 ms |
| *CPU throttled 4×:* full | 55 | 107 ms | 18.0 / 28.6 ms |
| *CPU throttled 4×:* condensed | 87 | 115 ms | 17.6 / 24.1 ms |

The "previous flat waterfall" rows were measured once, with the same
harness, against the component this layout replaced. The script measures
only the current one.

In the condensed layout, opening the 1,500-span cache group, stepping, and
*Show all* each paint within the two-frame floor (~33 ms; 49 ms to open the
group at 4× throttle). Scrolling the open list stays at a p95 of 18 ms. The
grouping pass itself takes about 1 ms for 5,000 spans (`condense.test.ts`).
`Waterfall.test.tsx` checks that neither layout mounts more than about a
screenful of rows.

Reproduce with `npm run bench:waterfall` or
`npm run bench:waterfall -- --throttle=4`. You need a Playwright Chromium
(`npx playwright install chromium`).
