# Changelog

All notable changes to `cboxdk/laravel-telemetry-ui` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.5.2] - 2026-09-25

### Fixed

- **Grouping by a kind-qualified dimension requires the attribute too.**
  2.5.1 narrowed "group by Outgoing host" to client spans, which stopped
  inbound requests being counted as dependencies but traded one wrong
  answer for another: `kind = client` also matches every `db.query`, redis
  command and connect span, none of which carry `server.address`. The list
  filled with `(none)` instead.

  A dimension that needs a span kind to mean anything needs its attribute
  present as well — grouping by "Outgoing host" means the spans that *are*
  outgoing calls, not every client span in the trace.

## [2.5.1] - 2026-09-25

### Fixed

- **Explore's group-by now applies a dimension's span kind too.** 2.5.0
  qualified the entity pages but not Explore, so "group by Outgoing host"
  still reported every inbound request as a dependency — the same bug, one
  screen over, and on the surface people actually use for grouping. An API
  subdomain with 198 inbound requests sat at the top of the list.

  The qualifier now lives on `Dimension::qualifiers()`, so the entity pages
  and Explore read it from one place instead of each remembering to. It
  narrows the rows, the RED stats, the series and the exact group counts
  alike, so every number on the screen describes the same population. A
  group-by on a dimension without a `spanKind` is untouched — there is a
  test for that too, because narrowing every group-by would quietly drop
  rows.

## [2.5.0] - 2026-09-24

### Fixed

- **`telemetry-ui:check` no longer fails a healthy metrics connection.** The
  smoke test was `vector(1)`, and `vector` is a PromQL *function*: a backend
  can serve the read API without implementing it. telemetryd answers `1` and
  refuses `vector(1)`, so `check` reported `metrics FAIL — status 400`
  against a connection that was working perfectly. The probe now asks for
  the series index — metadata, the same way the traces and logs probes ask
  for tag and label values — and falls back to a bare scalar for drivers
  without one. It also answers something worth printing: how many metric
  names are actually there.

- **"Outgoing hosts" no longer lists inbound requests.** The entity is keyed
  on `server.address`, which means two different things depending on span
  kind: on a CLIENT span it is the remote peer, on a SERVER span it is the
  local host that received the request. Without a kind qualifier every
  inbound request was counted as somewhere the app calls out to — and the
  trace you opened from there was the receiving side, showing no outgoing
  call in its waterfall, because there never was one. An API subdomain
  serving 300 requests appeared as a dependency with 300 outgoing spans.

  `Dimension` gains a `spanKind` parameter and the entity index, story and
  error-marking queries all apply it. The older outgoing-detail panels
  already filtered on `kind = client`; the v2 entity surface did not.

### Added

- **Transfer phases on an outgoing HTTP span.** `http.client.dns_ms`,
  `tcp_ms`, `tls_ms`/`connect_ms`, `ttfb_ms` and `transfer_ms` have been
  recorded for a while but were visible only as raw attributes. The span
  detail now draws them as a segmented bar with per-phase figures, so "the
  call took 284ms" becomes "the TLS handshake took 240ms of it".

  Widths are normalised against the sum of the phases, not the span: Guzzle
  follows redirects itself, so the phases describe the final hop while the
  span covers them all, and normalising against the span would shrink every
  segment on a redirected call and read as a fast request. A reused
  connection says so instead of reporting a 0ms lookup, and a span whose
  phases fall well short of its duration says the phases cover the final hop.

- **A `Connect` category in the waterfall.** `db.connect` and `redis.connect`
  spans (cboxdk/laravel-telemetry 2.7.0) get their own colour, tested before
  the database and cache categories they would otherwise fall into. A
  handshake is its own kind of wait, and it is what you are looking for when
  the first query sits behind an unexplained gap.

## [2.4.1] - 2026-09-24

### Fixed

- **An entity page no longer scans every log stream for exceptions.** With no
  service selected it looked for exception records with
  `{service_name=~".+"}`, which makes Loki read every stream it has — 6.5 s of
  an 8.2 s page on a busy store. It now reads only the services the entity's
  traces ran in: the same page answers in 1.4 s.
- **A page mounted in a host fills the host's width.** `.t-page` centred itself
  with `margin: 0 auto`, which on a flex child also turns off stretching, so an
  embedded page shrank to its content — a narrow sliver while it was loading —
  and stopped at 1680 px on a wide screen. Inside `.t-scope` it now takes the
  full width and leaves the gutter to the host.
- **Slow reads say what they are reading.** The entity page, the entity list,
  Explore and the error and issue drawers show a line such as "Reading this
  route's requests from the trace store…" above a full-width placeholder
  instead of a bare grey block, and the entity page shows "Updating…" while a
  new window loads. New `Loading` state component.

## [2.4.0] - 2026-09-24

### Changed

- **The trace drawer draws as soon as the trace store answers.** It fetches
  the trace with `?without=context`, then the metrics, logs, profile and
  exceptions around it from the new `traces/{id}/context`, and shows that it
  is reading them meanwhile. Measured through a host on a busy Tempo: the
  drawer draws after a median 1.0 s instead of 2.8 s (worst 1.1 s instead of
  4.5 s); the context follows about 1.7 s later without blocking it.
  `traces/{id}` without the parameter answers as before, and `useTrace` still
  returns the whole trace; the drawer uses the new `useTraceStory` and
  `useTraceContext`.

## [2.3.0] - 2026-09-24

### Added

- **Faster trace lookups when the start is known.** Trace links carry `at`
  (epoch ms) wherever the list knows when the request started — Explore, the
  request log, trace search, slow queries, entity and detail pages — and the
  drawer sends it as `traces/{id}?at=`. A backend that implements the new
  optional `LocatesTracesInTime` contract (Tempo does) is then asked about
  `traces.lookup_window` seconds either side only (default an hour; measured
  on a busy Tempo: median 0.9 s against 2.1 s, worst 1.0 s against 5.0 s). A
  miss falls back to the full lookup. `TelemetryTrace` takes `at` too.
- `SourceException::$httpStatus`: the backend's HTTP status, when it answered
  with one.

### Fixed

- Opening a trace a second time within the baseline cache's lifetime failed
  with a TypeError on a Redis or Memcached cache: those stores hand a cached
  number back as a string, and the context baseline was returned as cached.

## [2.2.0] - 2026-09-24

### Added

- Context signals take `{host}`, `{service}` and `{environment}` as well as
  `{scope}`, so a trace's context can come from exporters that label their
  series their own way (`node_load1{nodename="{host}"}`,
  `mysql_global_status_threads_running{environment="{environment}"}`). A signal
  that needs a value the trace doesn't have is skipped, not run unscoped.
- `keep_zero` on a context signal keeps a flat-zero tile: an empty worker or
  listen queue is the answer to "was this server overloaded".
- `hosts.cpu`, `hosts.memory` and `hosts.host_label`: the Hosts table's CPU and
  memory columns from node_exporter (or any exporter) instead of the OTel host
  metrics, held to the viewer's environments through `{environment}`.

## [2.1.1] - 2026-09-24

### Fixed

- Hovering down a list of traces no longer queues a full trace lookup for
  every row the pointer crosses. A trace is warmed only once the pointer has
  rested on its row for 200 ms, and a newer hover replaces a waiting one, so the
  trace that gets clicked is not stuck behind thirty others in the trace store.
- The trace drawer says it is loading the trace from the trace store, instead
  of showing a blank block for the seconds a lookup can take.

## [2.1.0] - 2026-09-24

### Added

- **Configurable label names** (`scope.labels.{metrics,traces,logs}.{service,environment,host}`,
  each with an env override). The scope lock, the pickers, fleet discovery,
  trace context, the hosts table and every log view used to hardcode the
  names `cboxdk/laravel-telemetry` emits, so a fleet whose telemetry comes from
  a Prometheus/Alloy scrape (`environment`, `hostname`) or Beyla
  (`deployment.environment`) saw an empty dashboard once a lock was set.
- **`<TelemetryToolbar />`** for embeds: the service, environment, window and
  refresh controls, without the standalone palette and shortcuts.
- **`href` on `TelemetryUiProvider`**: where an embedded anchor points, so
  middle-click and "copy link" open the host's page when the host serves the
  pages itself.
- **`@cboxdk/telemetry-ui/components.css`**: the component rules without the
  `:root` tokens, for a host whose own design system uses the same token names.

### Fixed

- A trace opened by id is checked against the **environment** lock as well as
  the service lock: a trace that ran in another environment, or does not say
  where it ran, is answered as not found.
- A trace's log lines and profile are looked up in the trace's own services
  only, over five minutes either side instead of an hour, rather than scanning
  every stream in Loki (`{service_name=~".+"}`) — on a large store that was
  most of a trace's load time.
- A request that reached the backend and timed out is no longer retried; the
  same query would only take as long again, so one slow query cost
  `(retries + 1) ×` the timeout. Connections that could not be made are still
  retried.

## [2.0.1] - 2026-09-23

### Changed

- Requires `cboxdk/laravel-telemetry` ^2.5, the release with
  `Telemetry::ignorePaths()`: `ignore_own_requests` (default on) now always
  keeps the dashboard's own page loads and API calls out of the app's traces
  and request metrics, instead of doing nothing on 2.0–2.4.

## [2.0.0] - 2026-09-23

A rewrite of the presentation layer: Livewire is removed and the dashboard is
now a versioned JSON API plus a prebuilt React single-page app. The query core
(contracts, drivers, query IR, result DTOs, `Analysis/`, the MCP server) is
carried over unchanged. See [UPGRADE.md](UPGRADE.md#1x--20) for every breaking
change with before/after code.

### Added

#### The platform

- **JSON API under `{path}/api/v2`.** `bootstrap`, `view-state` (POST),
  `pages/{page}`, `panels/{panel}`, `explore/{signal}` and `facets/{signal}`
  (requests, traces, logs, errors), `entities/{type}` and
  `entities/{type}/story?value=`, `traces/{id}`, `errors/{group}`,
  `issues/{id}`, `POST issues`, `annotations`, and `stream/{logs|requests}`
  (Server-Sent Events; `?once=1` for a single batch). Every endpoint takes the
  scope params `period`, `from`, `to`, `service`, `env`, `where[]`, and errors
  are typed: `{"error":{"type":"backend|forbidden|invalid|not_found","message"}}`
  with 502/403/422/404. See [docs/core-concepts/api.md](docs/core-concepts/api.md).
- **React SPA** (React, Vite, TypeScript, TanStack Query/Router/Virtual,
  ECharts in a lazy chunk), built to `public/build` with hashed chunks and
  committed, so hosts need no Node toolchain. A catch-all route serves the
  shell; routes are `/`, `/explore/$signal`, `/entities/$type`,
  `/entity/$type?value=`, `/p/$page`, `/errors/$group`, `/traces/$traceId`,
  with a stacked, deep-linkable drawer in `?drawer=trace:…~error:…~issue:…`.
  ⌘K command palette, ⌘. to collapse the subnav, brush-to-zoom sets the global
  time range, live tail over SSE with a polling fallback. Cbox design system,
  light by default with a dark theme.
- **`Panels\Panel`**, the framework-free replacement for `Card`, with
  `data(): array`, `static id()`, `static span()` and a `boot()` hook.
- **`Panels\Ui`**, builders for the payload contract (`table`, `stats`, `bars`,
  `composite`, `header`, `kv`, `code`, `callout`, `hidden`, cells, columns,
  controls) and for links (`entity`, `page`, `trace`, `error`, `issue`,
  `explore`, `param`, `url`). Mirrored by `resources/app/src/api/types.ts`.
- **`Panels\Attributes\Param`**, binding a public panel property to a query
  parameter.
- `Http\Api\RequestScope`: the per-request scope (window, service/env,
  filters, extra params), bounded by the tenancy lock, falling back to the
  reader's remembered view state for anything the URL doesn't state.
- Vitest unit tests for the SPA (`npm test`); PHP feature tests ported to hit
  the API endpoints.

#### Explore and entities

- **Explore**: one surface over requests, traces, logs and errors with a filter
  bar, facet panel, headline stats, a time × latency heatmap, group-by and a
  virtualised result list. Filters use `where[]=key<op>value` with
  `= != =~ !~ > >= < <=`; free text via `q`.
- **Entity pages**: a story per dimension value — insights, RED, trend with
  deploys, breakdowns with failure lift, status mix, correlated exception
  groups, failing and slowest traces — plus a Metrics tab that runs the v1
  detail panels for that entity and a Raw tab last.
  `TelemetryUi::entityPage($entity, $page, $param)` attaches a page's panels to
  an entity type; built in: route, job, queue, host, query, outgoing, path.
- **See the query**: Explore shows the compiled TraceQL / LogQL for the exact
  view, with "copy query" and "copy as curl" (`query` on the explore payload).
- **Value typeahead in the filter bar**: type `user.id=` and the values in
  this view are offered with their counts (and their resolved names).
- **Saved views**: name the current page + filters + window, recall it from the
  button or ⌘K (per browser).
- The Explore query bar sticks while you scroll the results.

#### Dimensions

- **Dimensions**: `TelemetryUi::dimension()` / `removeDimension()` /
  `dimensions()`. A declared dimension (label, group, optional link out as a
  closure or a `{value}` URL template, entity slug, scope, signals, format,
  plural) appears in facets, group-by menus, filter chips and as clickable
  chips, and gets an entity page. Built-in dimensions cover the attributes
  laravel-telemetry v2 emits (route, status code, user, client IP, host,
  query, job, queue, outgoing host, …). Facets are exact when the traces
  backend implements `AggregatesSpans`, otherwise counted over a labelled
  read-side sample.
- **Names instead of ids**: `TelemetryUi::resolve('user.id', User::class,
  'name')` (Eloquent model + attribute/closure, optional match column) or a
  batch closure, also as `dimension(..., resolve:)`. The SPA shows the name
  next to the id on row chips, facets, filter chips, group-by tables, entity
  lists and the entity page title, via `GET api/v2/dimensions/labels`: one
  request per dimension per tick, cached per value (misses too) for
  `telemetry-ui.dimensions.label_ttl`, and fail-open.
- **Derived dimensions**: `TelemetryUi::dimension('portal.screen', from:
  'http.route', pattern: 'portal:{value}')` promotes a value that lives
  *inside* another attribute to a first-class dimension — facet, chip,
  group-by, filter and its own entity page — without touching the emitter.
  Filters compile back to an exact query on the source attribute
  (`http.route = "portal:checkout"`, `=~` and "any value" to one anchored
  regex), so nothing is filtered read-side; only the facet counts are
  sampled, and the payload says so.

#### Extending

- **`Panel::statLinks()`**: a panel names where each headline number leads
  (label → link); the API fills it in for stats without a link. Used across
  the built-ins: job/command outcomes → their spans (failed →
  `status=error`), rate-limit rejections → 429s, N+1 → requests with
  duplicate queries, cache/storage → requests that touched them, analytics
  and page views → the analytics events (grouped by session for visitors),
  outgoing failures → client spans that failed. Commands, duplicate queries
  and a query's callers link their rows too (`Ui::rootOperation()`).
- **`TelemetryUi::metricPanel()`**: declare a chart over any metric instead of
  writing a panel class — gauge, counter (`rate:`), histogram (`quantile:`),
  split `by:` a label, narrowed with `where:`. A sidecar in another language
  that exports OTLP gets a real page with no PHP per chart.
- **`TelemetryUi::routeFamily()`**: one call turns a naming routing layer into
  its own area — a page with the family's throughput and a per-value table
  (prefix stripped, each row opening that value's page) — and declares the
  matching derived dimension, so the same values are facets and filters
  everywhere else — the same shape as the built-in Livewire page.
- **Embeddable components**: the React source ships inside the composer
  package as an npm package (`@cboxdk/telemetry-ui`), installed from
  `file:vendor/cboxdk/laravel-telemetry-ui/resources/app` — no registry. A host
  app mounts `<TelemetryPanel>`, `<TelemetryExplore>`, `<TelemetryEntity>`,
  `<TelemetryTrace>` or a whole page inside its own chrome, against the same
  gated, scope-locked API. The view state lives in React by default and can be
  bridged to the host's URL; the stylesheet carries tokens and components only,
  scoped to the provider's root, with fonts as a separate optional import.
  Rows open traces and issues in the same drawer, inside the host's page; a
  link to a different dashboard page goes to `onNavigate` or the full
  dashboard. Internally this is a navigation adapter: the components read their state
  through an interface, so the standalone dashboard (TanStack Router) and an
  embedded mount share one implementation.

#### Screens

- **Dashboard: Routes needing attention** — the eight routes with the most
  server errors, then the slowest p95, with a link to the full routes table.
- **Cache by store** and **Storage by disk** panels: per-store hits, misses,
  writes and hit ratio (a cold store no longer hides behind a hot one), and
  filesystem operations per disk and operation.
- Service graph derived from sampled client and database spans when Tempo's
  service-graph metrics are absent.
- "Called from" breakdown (the trace's root operation) on query/view/job/
  outgoing entity pages; occurrence wording for span-level entities.
- **Waterfall**: time ruler with gridlines, bars coloured by what the span does
  (app, database, cache/Redis, outgoing HTTP, queue, views) with a legend,
  duration labels beside the bar, no repeated names.
- **Stacktraces read like Sentry's**: paths relative to the project root, app
  frames emphasised, runs of framework frames folded to one expandable line,
  a raw view one click away (`Ui::code(..., 'stacktrace')`).

#### Working in it

- **No dead ends.** Every number that names a subset opens it: KPI tiles on
  panel pages (status classes, error rate, p95, events/users of an issue),
  Explore headline stats toggle a filter (error rate → `status=error`, p95 →
  `duration>=p95`, log errors → `level=error`), heatmap cells open their time
  window + latency band, group-table counts and error rates filter to that
  value, entity-story tiles and a new **Recent** list, deploy rows open the
  errors after the deploy, service-graph edges open the peer, "Called from"
  values open the route. Issue occurrences open Explore logs
  (`exception_group=`), grouped by user or filtered by release; occurrences
  whose trace was sampled away open the service's logs around them.
- **Context everywhere.** The trace story ends with "Around this request"
  (same route ±15 min, service logs ±2 min, errors ±15 min, this user, this
  IP, everything ±1 min); report rows open the query/view/outgoing host/job
  entity; chain hops open the service. Expanded log lines offer lines around
  (this service or all), requests around, the whole trace and the issue.
- **Keyboard layer**: `?` opens the shortcut sheet (also the topbar's `?`),
  `/` focuses the page's search, `[` / `]` step the time window by its own
  length (never past now), `n` jumps back to now, `r` refetches everything.
- **Triage without the mouse**: with a trace open, `j` / `k` step through the
  result list in place (neighbours prefetched), live-tail rows flash once as
  they arrive, and row times carry the full timestamp.
- **⌘K** never dead-ends: free text offers "search requests / logs /
  exceptions", a path offers its route page.
- **Hover prefetch**: links, table rows, result rows and sidebar pages warm
  their data on hover, so the click lands on a rendered page.
- **Empty results offer the way out**: back to now, widen the window, clear
  the filters — whichever applies.
- **No nested scroll boxes**: long tables, result lists and log lists
  virtualise against the page scroll; code blocks fold with "Show all";
  facets fold on narrow screens.
- **Phones**: the rail becomes a bottom tab bar and the area's pages open from
  a section bar; the topbar wraps; tables that can't fit become cards; result
  and log rows reflow.
- Content-aware table column widths, two-line cells (`sub`), two-column panel
  grid, per-route document titles, favicon, safe Markdown for issue bodies,
  panel links inside dimension menus ("filter this panel"), phone layout.
- **Ignores its own traffic**: with `cboxdk/laravel-telemetry`'s
  `ignorePaths()`, the dashboard's path is no longer traced
  (`telemetry-ui.ignore_own_requests`, default on).

### Changed

- `laravel/mcp` 0.9 and 1.x are supported alongside 0.8 (`~0.8.2 || ^0.9 ||
  ^1.0`); the MCP server serves both the legacy `initialize` handshake and the
  2026-07-28 protocol, and advertises the installed package version.
- **Cards are panels.** `Cards\Card` → `Panels\Panel`,
  `Cards\Builtin\*` → `Panels\Builtin\*` (same basenames);
  `TelemetryUi::card()/setCards()/removeCard()/cards()` →
  `panel()/setPanels()/removePanel()/panels()`; config `telemetry-ui.cards` →
  `telemetry-ui.panels`; `render(): View` → `data(): array`; `#[Url]` →
  `#[Param]`; `pageUrl()` → `pageLink()` returning a link payload.
- **Routes.** Pages moved from `/{path}/{page}` to `/{path}/p/{page}`;
  `/{path}/traces/{id}` is now an SPA route; the `?trace=` / `?issue=` /
  `?exception=` drawer params became `?drawer=`; assets moved from
  `/{path}/assets/{asset}` to `/{path}/build/{path}`. The route names
  `telemetry-ui.page` and `telemetry-ui.trace` are replaced by the catch-all
  `telemetry-ui.spa` and the `telemetry-ui.api.*` names.
- **Authorization.** The `viewTelemetryUi` gate runs on every dashboard and API
  route. Per-page checks (the gate's second argument) apply to the page and
  panel endpoints, the navigation in `bootstrap`, Explore/facets (by the page
  that covers the signal) and entity pages (the `requests` page).
  `manageTelemetryUi` guards `POST /api/v2/issues`.
- **View state** is persisted only on SPA shell renders and on
  `POST /api/v2/view-state`, never on API reads, so a page fetching ten panels
  sets no cookies and fires no `ViewStateChanged` events.
- The default `telemetry-ui.throttle` is now `600,1` (was `120,1`): the SPA
  sends one request per panel, facet list and Explore query.

### Removed

- The `livewire/livewire` dependency, `src/Cards/`, `resources/views/`, the
  Blade components, `resources/js/telemetry-ui.js`, `public/telemetry-ui.js`
  and `public/telemetry-ui.css`.
- Embedding cards in host Blade pages (`@telemetryUiAssets`,
  `<livewire:telemetry-ui.*>`, the `:embedded` prop). Replaced by the React
  components (see Added); a Blade-only host links to the SPA or reads the JSON
  API.
- The `telemetry-ui:period-changed` and `telemetry-ui:refresh` Livewire events
  and the gate middleware on `/livewire/update`.

### Fixed

- **p95/p99 charts were in seconds.** `PromqlCompiler` dropped the scalar
  (`times()`) on histogram quantiles, so a seconds histogram scaled ×1000 to
  milliseconds charted its quantiles unscaled. The scalar now applies to
  `histogram_quantile(...)` too.
- **Web Vitals from both browser SDKs.** `@cboxdk/telemetry-browser` sends one
  `browser.web_vital` marker span per metric (`web_vital.name/value/rating`);
  the Web Vitals and page-performance panels only read laravel-telemetry's
  `web-vitals` span. Both are read now (`Panels\Concerns\ReadsWebVitals`), and
  FCP/TTFB are shown when present.
- **Hosts on backends whose metrics carry no host label** (telemetryd keeps
  `host.name` on the resource only). The hosts table lists hosts from traces
  and attributes unlabelled metrics to a single host; host-detail charts match
  unlabelled series; `_ratio`/bare metric-name variants both match.
- **Host services** no longer fail as a whole when one probe can't run; the
  default observed-service probes drop the `> 0` PromQL comparison some
  backends reject.
- **Bounded payloads on span-heavy searches.** Some backends return every
  matched span per trace; unfiltered trace searches default to server spans,
  span-level entities (queries, views, jobs, outgoing hosts) sample adaptively
  and count each matched span as an occurrence.
- **Failures without an HTTP status** (a failed job, a failing query) are found
  via a `status = error` search, so entity stories and trace rows report them.
- **Negated regex on backends that refuse it** (`!~` on telemetryd) is applied
  read-side on a wider sample, and the UI says so.
- **"Why it failed"** in the trace story names the exception (Loki records by
  trace id, else the service's records in the request's own time window,
  marked as a likely match); trace logs use the same fallback.
- A trace's exception records were looked up across every service in a
  20-minute window on each trace open; the lookup now selects the trace's own
  services (exact, or an alternation when it crosses services) in a 2-minute
  window.

- The Duration panels (dashboard, route pages) failed from 24h up on
  telemetryd ("query matched more than … records"): the average's sum and
  count are now two range queries divided per point instead of one binary
  expression holding both.

- Switching Explore signals no longer renders the previous signal's rows with
  the wrong list (it crashed going from requests to logs).

## [1.5.0 – 1.9.0] - 2026-08-08 – 2026-08-12

These changes shipped across the 1.5.0 to 1.9.0 releases; the changelog was not
split per version at the time.

Built on `main` before the v2 rewrite; these entries describe the 1.x Livewire
UI and are kept as written.

### Added

- **`Contracts\EnumeratesMetricNames`, an optional metrics-driver capability.**
  `metricNamesMatching(array $patterns, string $scope = '')` returns the metric
  names present that match any of the patterns, so page detection can ask about
  every pattern at once. Prometheus and Mimir implement it from the series
  index. Optional the way `AggregatesSpans` is: `SchemaDetector` feature-detects
  it, so third-party drivers keep working unchanged.
- **Shared view state that survives navigation — `Support\ViewState`.** The time
  window, the auto-refresh interval and the service/environment scope used to
  live only in the query string, so they survived exactly the links that
  bothered to carry them: a host's `navLink()`, a card's deep link, a trace
  drill-in or a plain reload of a bare URL all dropped silently back to the last
  hour. They are now resolved once per request and remembered in a cookie.
  An explicit URL parameter still wins, and taking one updates what is
  remembered — so a shared deep link shows the sender's view and a bare link
  shows the recipient's saved one. Read it with `TelemetryUi::viewState()`, move
  it with `put()`, and listen for `Events\ViewStateChanged`. See
  [docs/extension-points/view-state.md](docs/extension-points/view-state.md).
- **`TelemetryUi::connection()` / `currentConnection()`.** A host that mounts the
  dashboard as its whole UI can offer its backend profiles as a native `<select>`
  in the dashboard header, instead of making the reader leave for a host screen
  and come back. Nothing registered means nothing rendered, as with `navLink()`;
  label and URL are escaped and there is no markup parameter, holding the same
  line `NavLink` holds.

### Changed

- **Page detection resolves every pattern in one backend call.** The shell asked
  `count({__name__=~"…"})` once per page that declares a `detect` pattern —
  sixteen built-in patterns, sixteen sequential round trips. Against a remote
  Grafana datasource proxy (~140 ms RTT) that was 2.3 s of pure latency before
  the first pixel, appearing as the dashboard "randomly" going slow whenever the
  detection cache lapsed. `SchemaDetector::detect()` now asks about all the
  uncached patterns at once and decides each one against the returned metric
  names: 16 calls → 1, and 2340 ms → 179 ms with the same latency injected.
  Scope still batches (the selector carries the service/environment matchers),
  caching is still per pattern so a partly warm cache only asks about what it
  is missing, and a backend that cannot answer still fails open uncached. Public
  API is unchanged; `hasMetricsMatching()` delegates to the batched path.
- **The page header is sticky within `.tui-main`.** The title, scope switcher and
  period selector are what a reader reaches for most on a long page and hardest
  to find once scrolled past. Themed translucent pane with a blur (opaque
  fallback where `backdrop-filter` is unsupported), parked below the mobile
  topbar so the two never fight.
- **"Refresh now" re-dispatches the Livewire refresh event** instead of calling
  `window.location.reload()`, so an open drawer, the scroll position and any
  client-side selection survive a refresh — and it costs one round trip rather
  than a whole page render. Falls back to a reload if Livewire has not booted.
- **"Copy link" pins the resolved state into the URL** rather than copying the
  address bar verbatim. Now that the window lives in a cookie, a copied bare URL
  would have silently retargeted to whatever range the *recipient* last used.

### Fixed

- **The auto-refresh control could show an interval other than the one running.**
  The timer was restored from `sessionStorage` while the combobox labelled itself
  from the DOM's selected `<option>`, which the server always rendered as "off" —
  and Alpine's `x-model` write of the restored value neither changes the
  `selected` attribute nor fires `change`, so the label never caught up. The
  interval is now server-rendered from the shared state and handed to Alpine as
  the same number, so the label and the timer have one source.
- **"Reset zoom" now pins the preset period.** It only deleted `from`/`to`, which
  with a remembered range would have resolved straight back to the range it was
  meant to clear.
- **"All services" / "All envs" set an empty scope parameter instead of deleting
  it.** An absent parameter is indistinguishable from "not specified", so
  clearing the scope would otherwise have been undone by the remembered one.

## [1.4.1] - 2026-08-07

### Fixed

- The `TelemetryUi` facade's `@method` annotation for `resolveConnectionsUsing()`
  never gained the `needsViewer` parameter added in 1.4.0, so a static analyser
  flagged every correct call as passing an unknown argument.
  `connectionResolverNeedsViewer()` is annotated too.

## [1.4.0] - 2026-08-07

### Added

- **Connection probing — `Contracts\ProbesConnection`.** A driver can now answer
  "is this connection actually usable?" without running a dashboard query, via
  `ConnectionManager::probe($name)`. The result is a classified `ProbeResult`
  (`BackendStatus`: reachable / TLS / unauthorized / not-found / unexpected-API),
  because "it didn't work" is useless — a wrong hostname, an untrusted CA, a bad
  token and a URL pointing at the wrong product need four different fixes.
  Implemented by the Tempo, Loki, Prometheus and Mimir drivers, each checking the
  API *shape*, so a Loki URL pasted into the traces field is caught up front
  rather than failing on every card. `probe()` never throws — a malformed config
  comes back as a failed result.
- **Per-connection TLS options.** Connections accept `verify`: `true` (default),
  a path to a custom CA bundle for an internal PKI or self-signed certificate, or
  `false` to skip verification. Env keys `TELEMETRY_UI_{METRICS,TEMPO,LOKI}_CA_BUNDLE`.
  Only an explicit boolean `false` disables verification — a stringy `"false"`
  from an env var is read as a CA path and fails closed, rather than silently
  downgrading TLS on a typo.
- **`Contracts\WritesToBackend`.** A marker making the read-only posture
  checkable instead of claimed: no telemetry read driver implements it, and
  `CreatesIssues` now extends it. A host that promises "this never writes to your
  stores" can assert that, rather than documenting it.
- **`resolveConnectionsUsing(..., needsViewer: false)`.** For hosts with no
  authentication at all, where the connection is a property of the process rather
  than of a user. The default stays viewer-gated, so existing multi-tenant
  resolvers — which dereference the user — are never handed `null`.

### Changed

- `SourceException` now carries a `BackendStatus`, classified at the throw site
  where the HTTP status and transport error are still available, instead of
  leaving callers to pattern-match on message text.
- Dropped `final` from the connector classes per the house rule that package
  classes stay open to extension (`ApiClient`, `ConnectionManager`,
  `ResolvedConnections`, `SourceException`, `TempoSource`, `LokiSource`,
  `MimirSource`).

## [1.3.0] - 2026-08-07

### Added

- **Statamic pages carry a breakdown, not just a chart.** Under each thin
  overview chart, a list a reader can scan: Static Cache by outcome, Glide by
  preset, Forms by form, Content by type — which item dominates, without
  per-item spans. Stache (no facet label to group by) gains a **warm-build
  latency** table instead — the p50/p75/p90/p95/p99 distribution behind its
  single P95 stat.
- **Analytics audience tables now show visitors, not just views.** The
  Campaigns and Sources & audience breakdowns gain a **Visitors** column
  beside Views (the distinct-visitor count was already computed but never
  shown), with a fixed table layout so the compact side-by-side tables can't
  overflow into each other.

### Changed

- **Navigation reorganised.** Queues split into their own sidebar group, and
  Monitoring broken into **Frontend** (Analytics, Web Vitals, Users) and
  **Infrastructure** (Hosts, Logs, System) — so each area is a focused tier-1
  entry instead of one long list.

### Fixed

- **Chart bars no longer vanish on hover.** On the bar charts (e.g. a page's
  Traffic), ECharts' emphasis state repainted the stacked step-area with a
  collapsed baseline, so the fill disappeared while the pointer was over it.
  Emphasis is disabled on the series (the axis tooltip is the hover
  affordance); annotation markers keep their own emphasis.
- **Detail pages light up the nav again.** A hidden detail page (a page,
  issue, host, request, …) now highlights its list page in both the icon rail
  and the subnav via a declared `parent`, instead of leaving the whole
  navigation with nothing active.
- **Web Vitals aggregate summary restored.** The Core Web Vitals card passed
  its p75 LCP/CLS/INP summary to the stats component under the wrong attribute
  (`:stats` instead of `:items`), so the headline was silently dropped and the
  page rendered as a bare table.

## [1.2.0] - 2026-07-15

### Changed

- **Require `cboxdk/laravel-telemetry ^1.0`** (was `^0.3.0`). Aligns the dashboard
  with the now-stable telemetry 1.0 line — the old `^0.3.0` constraint excluded
  telemetry 0.4 and 1.0, so a fresh install pinned an older telemetry. No
  dashboard code changes; verified against telemetry 1.0.0 (326 tests green).

## [1.1.0] - 2026-07-15

A visual overhaul plus New-Relic-style database dashboards. No PHP API changes —
the `MetricsSource` / `TracesSource` / `LogsSource` contracts and every result
DTO are unchanged from 1.0.0, so drivers built for 1.0 keep working untouched.

### Added

- **Query performance dashboards** (New-Relic style): DB queries aggregated by
  normalised statement and ranked by the total DB time they consume (not just
  the single slowest run), with calls / avg / p95 / max / share, N+1 detection,
  a per-minute throughput chart, and a query-detail drawer.
- **Searchable comboboxes** replace every native `<select>` (scope, period,
  auto-refresh, and all per-card filters). Type-ahead filtering, full keyboard
  navigation (↑/↓/Enter/Esc), and a selected-state check. Each wraps a hidden
  native `<select>` so `wire:model.live`, `x-model`, and form navigation keep
  working unchanged.

### Changed

- **Full reskin to the Cbox design system**: warm-neutral oklch tokens, a
  **light theme by default** with a dark toggle (persisted, no flash-of-theme),
  Plus Jakarta Sans display + JetBrains Mono for data/IDs/numbers, and clear
  1px borders. Charts (ECharts) now read the design tokens, so they follow
  light/dark automatically.
- **Two-tier app shell** (Intercom model): a 56px icon rail — three states
  (minimised / hover-overlay-with-labels / pinned in-flow) — plus a collapsible
  contextual subnav (header toggle or `⌘.`). Both states persist in
  localStorage.

### Notes

- Cosmetic-only heads-up for downstream customisation: the legacy `--tui-*` CSS
  variables are now **remapped onto Cbox tokens**, and the filter/scope pickers
  no longer render a *visible* native `<select>` (a hidden one is retained for
  binding). If you overrode `--tui-*` values or styled those selects directly,
  review your overrides — no code migration is required.

## [1.0.0] - 2026-07-07

Backend-neutral query IR — the read side no longer speaks PromQL/TraceQL/LogQL
strings directly, which opens the dashboard to non-LGTM backends (see the
companion `cboxdk/laravel-telemetry-store` for a native ClickHouse store).

### Changed

- **BREAKING:** the `MetricsSource` / `TracesSource` / `LogsSource` contracts now
  take typed query objects (`MetricQuery` / `TraceQuery` / `LogQuery`) instead of
  dialect strings. Result DTOs are unchanged. Custom drivers must implement the
  new signatures — see [UPGRADE.md](UPGRADE.md).

### Added

- `Queries\Ir\*` (query objects, matchers, conditions) and `Queries\Compilers\*`
  (`Promql`/`Traceql`/`Logql` compilers). Cards build the IR; each driver
  compiles it to its dialect. A `*::raw()` escape hatch covers the few
  dialect-only cases (nested aggregation, config-driven exporter queries).
- Everything in 0.4.0 (below) also ships in this release.

## [0.4.0] - 2026-07-07

### Added

- **Scope lock (tenancy).** Constrain the whole dashboard to a fixed set of
  services / environments — dynamically per user via
  `TelemetryUi::restrictScopeUsing()`, or statically with no code via
  `telemetry-ui.scope.lock` / `TELEMETRY_UI_LOCK_SERVICES` /
  `TELEMETRY_UI_LOCK_ENVIRONMENTS`. The picker now reflects the lock: it offers
  only allowed values, drops "All" for a locked dimension, and hides a picker
  locked to a single value entirely. Enforcement stays at query time.
- **Page-detail show pages.** Analytics and Frontend rows now open a dedicated
  page-detail scoped to that one URL path — traffic (views, visitors,
  referrers, countries, devices), real-user performance (Core Web Vitals + load
  timings), the page's browser→backend traces, and its browser errors — instead
  of dumping into a pre-filtered trace search.
- **Richer visitor breakdowns.** The analytics "Sources & audience" card gains
  Sources, Regions, Cities, Operating systems and Browsers alongside Referrers,
  Countries and Devices, each toggleable via `telemetry-ui.analytics.dimensions`.
- **Marketing channel — a first-class, zero-cardinality dimension.** Visits are
  classified into Direct / Organic search / Social / Email / Paid / Referral /
  Internal, derived at read time from the referrer (and UTM medium + paid
  click-id once the emitter captures them), so it costs no ingest cardinality.
  Configure your own hosts with `telemetry-ui.analytics.internal_hosts`.
- **Campaigns card.** UTM campaign attribution (campaign / source / medium, and
  higher-cardinality content / term) from the emitter's
  `telemetry.analytics.utm` capture — with a single empty state until it's on.
- **Cardinality guide** in the analytics cookbook: per-dimension high/low
  classification, the capture-vs-surface control split, and the ClickHouse path.

### Fixed

- **Chart-annotation toggle** no longer shows a duplicate "Cache purge" (the
  Statamic marker is now its own labelled, distinctly-coloured entry) and its
  rows/labels align in one clean column.

### Changed

- **Requires `cboxdk/laravel-telemetry` ^0.3.0** for the UTM / campaign capture
  the Campaigns card and channel enrichment read.

## [0.3.0] - 2026-07-07

### Added

- **Dashboard cards drill into their pages.** Cards that summarise a
  dedicated page (Requests activity/duration, Exceptions, Jobs, queue and
  autoscale cards) gain a "Requests →"-style header link when rendered on
  the dashboard or as an embedded widget, carrying the active
  period/service/env scope. On the card's own page the link is suppressed.
  Package cards opt in by setting `protected ?string $drillPage = 'my-page'`.

- **Hide chart annotations per type.** The header gains a ⚑ toggle listing
  the configured marker types (Deploy, Incident, …) with a checkbox each,
  plus a show/hide-all master switch — uncheck the noisy ones and every
  chart drops those lines instantly. Purely client-side: cards always ship
  the full annotation set and the charts filter marker lines by kind, so
  toggling costs zero backend queries. The choice sits in the URL
  (`ann_off`), so it survives navigation and deep links.

- **Grafana-style relative time ranges.** `?from=now-1h&to=now`,
  `now-7d`, `now+30m` … (units s/m/h/d/w/M/y) work everywhere `from`/`to`
  do — evaluated at view time, so a shared relative link always shows the
  trailing window instead of a frozen one. Plain unix seconds still work,
  and the header shows relative expressions verbatim.
- **Livewire updates carry their component everywhere.** With
  `cboxdk/laravel-telemetry` ≥ 0.2.1, `POST /livewire/update` is named
  `livewire:{component}` (batched updates: `livewire:batch`), so the routes
  table groups per component instead of lumping thousands of opaque updates
  into one row. The request log shows the component(s) behind each update
  inline, and the Livewire page gains the Requests page's grouping/live-tail
  pair: a per-component table and a scoped live request log.
- **Collapsible sidebar navigation.** The nav groups (Activity, Monitoring,
  Statamic, …) collapse to chevrons so a long page list fits on one screen;
  only the active group opens by default and the choice persists in
  localStorage. Top-level items (Dashboard, Traces, Issues) stay visible.
- **Frontend page rows drill into their traces.** Core Web Vitals and Page
  performance rows open the browser→backend traces for that URL path, matched
  on `span.url.path`, carrying the active scope.

### Fixed

- **Drill links no longer appear on a card's own page.** Cards lazy-load in
  a follow-up Livewire request where the page route param is gone, so every
  card thought it was on the dashboard and grew a self-referencing
  "Autoscale →" link. The page view now passes the page slug into each card
  at mount.
- **Sparse counters no longer read as zero.** Scaling actions and SLA
  breaches born inside the selected window were invisible to `increase()`
  (Prometheus never sees the 0→first-value jump). Cards now count series
  births too, so "Scale down: 1" shows up the moment the first scale-down
  ever happens.
- **The Cluster card explains itself on single-host installs** instead of
  showing misleading "0 managers / 0%" stats — the `queue_autoscale_cluster_*`
  gauges only exist in cluster mode, and the card now says so.
- **Routes ⇄ Request log toggle no longer reloads the page.** The toggle was
  a plain link (full page load); it is now a Livewire event both sibling
  cards listen to, so the swap is instant and keeps scroll/filter state.
- **Request log renders full-width.** Its live-poll wrapper `<div>` was the
  card's grid item, so the `span 2` on the inner card never reached the grid
  and the log was squeezed into one column. The wrapper now uses
  `display: contents`.
- **Live tail is on by default** on the request log.
- **Scaling actions card matched nothing on real data.** The autoscaler's
  `direction` label carries `up` / `down` (the WorkersScaled action), not
  `scale_up` / `scale_down`; the card now groups by the label instead of
  filtering on guessed values. Verified against live production series —
  as are the rest of the autoscale names, including
  `queue_autoscale_sla_breach_ratio` and
  `queue_autoscale_sla_predicted_pickup_seconds`.
- **Environment scope now works on every log-based card.** The scope put
  `deployment_environment_name` in the Loki *stream selector*, but backends that
  index only `service_name` as a stream label (e.g. otel-lgtm) carry the
  environment as *structured metadata* — so a selected environment silently
  matched nothing and Analytics, the log viewer, unified errors and annotations
  all returned zero. The environment is now a pipeline label filter
  (`{service_name="…"} | deployment_environment_name="…"`), correct whether the
  backend indexes it as a stream label or not. The analytics Countries/Devices
  empty states now name the emitter flags (`TELEMETRY_ANALYTICS_GEO` /
  `TELEMETRY_ANALYTICS_UA`) that populate them.
- **Rootless traces no longer read as a perpetual "Loading…".** An orphaned
  browser page-view trace (no backend root span) showed "Loading…" forever in
  the drawer; it is now labelled (the span name, "Unnamed trace" or "Trace not
  found") since the fetch is already complete by render time.

## [0.2.1] - 2026-07-06

### Fixed

- Queue throughput cards queried `queue_metrics_queue_throughput_per_minute`;
  the OTLP collector translates the `{jobs}/min` unit to a `_per_min` suffix
  (verified against live data), so the Throughput card, the queues table's
  Jobs/min column and the queue-detail header matched nothing. Now they
  query `queue_metrics_queue_throughput_per_min`.

## [0.2.0] - 2026-07-06

### Added

- **Queues page** (autodetected via `queue_metrics_.*`) for fleets running
  `cboxdk/laravel-queue-metrics`' OpenTelemetry integration: backlog by
  state (pending/scheduled/reserved), per-queue throughput, oldest-job age,
  busy/idle worker fleet with utilization, and a per-queue table with
  backlog-trend sparklines. Each queue drills into a **queue-detail page**:
  headline numbers, backlog and throughput for that queue, the autoscaler's
  target-vs-active steering, and the job classes running on it (each linking
  on to its job-detail page).
- **Autoscale page** (autodetected via `queue_autoscale_.*`) for
  `cboxdk/laravel-queue-autoscale` v3.11+: target vs active workers,
  executed scaling actions by direction, SLA health (predicted pickup,
  queues in breach, breach transitions) and cluster capacity
  (workers/required/capacity, managers, utilization, recommended hosts).

### Changed

- Require `cboxdk/laravel-telemetry` `^0.2.0` — its first stable release
  (was `^0.1.0-alpha.3`).

## [0.1.0-alpha.6] - 2026-07-06

### Added

- **Copy an issue as Markdown for an LLM.** The issue page's Actions &
  context sidebar gains a **⧉ Copy for LLM** button that puts a
  self-contained Markdown brief on the clipboard — exception, message,
  location, occurrences/users, environment/release/host, the request that
  hit it, the suspect change, the releases it rode in on and the freshest
  stacktrace — ready to paste into a model with "help me fix this". No
  tracker write access required.

### Changed

- **Optional sidebar groups follow the selected service.** Schema detection
  now scopes to the active service/environment, so a group like **Statamic**
  (or Horizon, Reverb, …) only appears when *that* service emits its metrics —
  not because some other service in the fleet does. "All services" still
  detects fleet-wide, and the tenancy lock still bounds what a viewer can see.

## [0.1.0-alpha.5] - 2026-07-06

### Added

- **Issue rows go straight to the issue page** — no drawer detour — and
  the page gains a Sentry-style **Actions & context sidebar** next to the
  trend: a prefilled "+ Create ticket" button, the tracker tickets that
  already mention this exception (searched live), the key facts (first/
  last seen, users, env, release, host → its page, throw site) and the
  suspect change event.
- **Request log with live tail.** The Requests page's Routes card gains a
  toggle sibling: a request LOG — individual requests, newest first, with
  time, method+path, status badge, user, client IP and duration. Filter
  by user id, client IP, path or status class (each user/IP cell is
  click-to-tail), hit **● Live** and the list re-polls every few seconds —
  production debugging for "what is this user hitting right now?". Every
  row opens the readable request story in the pane.
- **Traces read like requests now — the waterfall is the last resort.**
  Opening a trace (pane or full page) tells the story instead of dumping
  spans: request facts (method, route, path+query, status, client IP,
  user, user agent, body sizes), cost totals off the root tallies
  (queries + query time, N+1 count, cache ops, redis commands, models
  hydrated, views, CPU time, memory), then one readable section per
  concern — **Database** (statements slowest-first, N+1 called out),
  **Upstream calls** (URL + status + duration), **Cache** (hit/miss/write
  summary + keys), **Redis**, **Dispatched jobs**, **Views**, **Storage**,
  **captured Headers** (request/response) and **Logs written during the
  request** (trace-correlated). The raw span waterfall collapses behind a
  "Raw trace — N spans" toggle. Job/command traces adapt automatically.
- **Purpose-built trace filters**: status-code class (2xx–5xx), path
  contains and client IP join status/source/route/name/duration — all
  URL-synced, all composing scoped TraceQL under the hood.
- **Full issue page (Sentry-style show view).** Every error group now has
  its own page (`error-detail?group=…`, the drawer's "Full page" button):
  header with events / users / first seen / last seen, an events trend
  chart with the deploy/change markers drawn on top (release markers,
  Sentry-style), **tag distributions** (host, environment, release,
  service, user — "is it one box, one release, one customer?"), and the
  full deep-dive: request strip, root-cause hints, source context,
  stacktrace and recent occurrences. Drawer and page share one
  per-request-memoized `ErrorGroupReport`, so the page's four cards cost
  one set of backend queries.
- **Users affected.** Error groups now count distinct users (from the
  `enduser.id` laravel-telemetry ≥ alpha.18 stamps on exception records):
  a Users column on the errors list and a "users affected" fact on the
  group panel.
- **Errors list filters.** Free-text filter over type/message and a
  source selector (server / web / full-stack), both URL-synced.
- **Scope moved to the top header.** Service and environment now sit
  next to the period picker on every page — Sentry's top-bar pattern —
  and the sidebar is pure navigation.
- **Full-color card borders.** The context tiles and root-cause box wear
  their accent color as a full border instead of a left edge.
- **Sentry-style errors list.** Every group row now carries its in-period
  trend sparkline, first seen and last seen, a **NEW** badge for groups
  born within 24 hours, and a sort control (events / last seen / first
  seen). First-seen looks beyond the page period (min 7 days) so it means
  what it says; counts and trends stay period-scoped.
- **Root-cause hints on the error-group panel.** The change event (deploy,
  migration, feature flag, …) closest before the group's first occurrence
  — within 48h — is named as the suspect ("Deploy v9.1.0 at 14:02 — first
  seen 18 minutes later"), and the panel shows which releases the sampled
  occurrences carry, with an "only this release" badge when every one
  points at a single release.

### Fixed

- **Annotation callout is anchored to its marker line again.** The deploy/
  change callout now sits hard against the line it describes — flipping to
  the line's other side near the chart edge (where deploys cluster) instead
  of detaching to a fixed corner — with a caret pointing back at the line.
  The raw axis tooltip no longer stacks on top of it: the series readout is
  suppressed while the callout is open, and the callout renders above it
  regardless. Hovering into the callout keeps it open to reach **Open
  trace**, so the click-to-pin affordance is gone.

## [0.1.0-alpha.4] - 2026-07-06

### Added

- **Error groups live on the Issues page too.** The unified Errors card
  now sits above the tracker list — "what's broken" and "what's filed"
  on one page.
- **Issue/PR bodies render as formatted markdown.** Dependabot's
  release-notes blocks, headings, lists, code and links display like on
  the tracker instead of raw tag soup — via a strict-allowlist sanitizer
  (structural tags only, every attribute dropped except validated http(s)
  hrefs; scripts/styles/iframes lose their payload entirely), because
  tracker bodies are external content.
- **Annotations are now interactive.** Each chart marker line carries a
  colored dot handle; hovering it opens a callout ANCHORED to the line
  (the pointer can move into it), and clicking pins the same callout in
  the same place — one shape, one position, both triggers. It shows the
  label, exact time, notes and an "Open trace" button into the emitting
  trace.
- **Rollout markers cluster.** A horizontal deployment emits the same
  marker from every host within minutes; 200 servers no longer draw 200
  lines. Same-kind+label events within a 15-minute gap window fold into
  one marker showing ×N, the rollout span (first → last host) and the
  covered hosts — on charts, in the callout and on the Deploys timeline.
- **Host detail page** — clicking a host (from the Hosts list or the trace
  context strip) opens its own page: headline CPU/memory/load/request stats,
  host-scoped system charts (CPU load, memory, network, filesystem), and a
  **Services on this host** card fed by the services' own Prometheus
  exporters — mysqld_exporter, redis_exporter, postgres_exporter and
  node_exporter probes ship as defaults, plus an app-side Redis section
  that needs no exporter at all. Config-driven (`telemetry-ui.host-services`,
  `{host}` token): a probe that returns nothing simply doesn't render, so
  listing exporters you don't run is free — and adding your own is a
  config entry, not code.
- **The trace context strip names its scope.** The host/runtime tiles now
  say exactly whose signals they show — the `host.name` that served the
  trace (linked to the Hosts page) and the service, or "all hosts" when
  the resource carries no host and the queries aggregate service-wide.
  `host.name` also joined the resource rows in the span attribute panel.
- **Exception groups link back to the request.** The error-group panel now
  shows env / release / host facts off the exception record (host links to
  its detail page), and a "Latest occurrence" strip off the newest trace
  root — method + route (linked to the route's detail page), status, user
  and the request trace. Occurrence rows are whole-row click targets.
- **The drawer is now a docked properties pane on wide screens.** At
  ≥1100px it pushes the page aside instead of covering it — no backdrop,
  the page stays fully interactive, and selecting another row simply swaps
  the pane's content (links *inside* the pane still stack with
  back-navigation). Narrow screens keep the overlay behavior.
- **"Database (seen by app)" host section** — the host page now shows
  database activity (queries/s, hourly volume, N+1 detections) from
  laravel-telemetry's new `db.queries` counter, no exporter required.
  MySQL/Postgres exporter probes remain the path to real health stats.
- **Host services tell the truth about visibility.** App-side sections
  (like "Redis (seen by app)") carry an `observed` badge instead of
  up/down — traffic measured by the app proves usage, not health — plus a
  note pointing at the exporter that would unlock full monitoring.
- **The drawer opens instantly.** Clicking a trace/issue/error row slides
  the drawer in immediately with a shimmer skeleton; the content morphs in
  when the backend queries land — no more click lag.

### Fixed

- **The route page's Paths card leaked every path in the backend.** It
  used Tempo's tagValues with a filter that v1 quietly ignores — a route
  detail listed unrelated URLs from the whole fleet. Paths now aggregate
  from the route's own spans with real numbers per path (requests, avg,
  max, 4xx/5xx) — a row tails that path in the request log, and ⇄ opens
  the newest request's story.

- **Charts no longer stick at the width they measured mid-render.** The
  ECharts instance now lives outside Alpine's reactive proxy (a proxied
  instance silently breaks `resize()`), and a `ResizeObserver` keeps the
  layout in step with the container — Livewire streaming a card in at
  zero width, sidebar/drawer toggles and orientation changes all heal.

## [0.1.0-alpha.3] - 2026-07-06

### Added

- **Mobile-friendly layout** — below 768px the sidebar becomes an off-canvas
  drawer behind a sticky topbar hamburger, header controls wrap, the trace
  waterfall drops the per-row service chip so span names stay readable, the
  custom-range popover anchors to the viewport, the trace drawer goes
  full-width, and inputs are sized to avoid iOS focus zoom.
- **laravel-telemetry v0.1.0-alpha.16 support** — five new auto-detected
  sidebar pages plus cards, each appearing only when the fleet emits the
  signals:
  - **Horizon** (`horizon_*`): worker processes per supervisor with
    paused/supervisor gauges, and an incidents chart (long waits, restarts,
    OOM kills, migrated jobs).
  - **Reverb** (`reverb_*`): active WebSocket connections per app with
    subscribers by channel type and pruned-connection counts, plus message
    throughput sent vs received.
  - **Feature Flags** (`feature_*`, Pennant): checks by flag with active
    share and per-result badges, and a warning strip for checks against
    unregistered flags.
  - **Storage** (`storage_operations_*`): Flysystem disk operations per
    minute by type, with per-disk totals.
  - **Livewire** (`livewire_*`): mounted vs hydrated components per minute,
    and a slowest-components table off the render/update/call detail spans.
  - **Rate limiting** card on the Requests page: 429s per minute by limiter.
  - **Core Web Vitals** card on the Frontend page: real-user p75 LCP / CLS /
    INP per path from the SDK's `web-vitals` spans, toned on Google's
    thresholds.
  - **Duplicate queries (N+1)** card on the Queries page, from the
    `db.query.duplicate_detected` log events — query text, traces affected,
    worst repeat count, trace link.
  - **CPU profile strip** on the trace view: when excimer captured a profile
    for the trace, the waterfall is headed by top functions by CPU share.
  - **Span links** (queue retries): linked traces render as clickable rows in
    a span's attribute panel.
- **Error-group detail drawer (Sentry-style issue view)** — clicking a row on
  the unified Errors card now opens a drawer with the exception's message,
  occurrence stats (count, first/last seen, source), the latest occurrence's
  **stacktrace and source context**, a prefilled "+ ticket" compose button and
  a recent-occurrences table whose trace links stack onto the drawer.
  Deep-linkable via `?exception=<group>`; searches are forced inside the
  viewer's tenancy scope lock.
- **Cache purge annotations** — two new built-in markers: `cache_purge`
  (app-agnostic, emit via `php artisan telemetry-ui:annotate cache_purge
  --id=redis`) and `statamic_cache_purge`, which matches the
  `statamic.cache.purge` events cboxdk/statamic-telemetry emits on every
  stache/static/glide clear — so Statamic purges show up as chart lines and
  timeline events with no wiring.

- **Issues across multiple repos** — `connections.issues` may now be a list of
  trackers (frontend, api, sidecar, …), each with a `label`. The Issues page
  aggregates them newest-first, tags each row with its repo, and adds a repo
  filter. A single connection still works unchanged.
- **Manual refresh button** in the toolbar, next to the auto-refresh control.
- **Trace list reads like requests** — rows now show the HTTP method, route and
  status (error-highlighted) off the root span, plus a `web` badge for RUM
  traces, instead of a bare span name.
- **Row drill-down on Analytics & Frontend** — a page row opens every trace for
  that path, which spans the browser page load *and* the backend request it
  triggered (frontend → backend in one waterfall).

### Changed

- Empty states on Analytics/Frontend now hint that the data lives under the
  app's own service, so an empty page nudges you to check the service scope.

### Security

- **Scope lock is now enforced on raw trace queries.** A hand-edited or
  deep-linked `?q=` on the Traces page ran verbatim, bypassing the tenancy
  lock; it is now forced into the viewer's allowed services (drill-down links
  go through the scoped builder too).
- **Scope lock fails closed.** A viewer whose resolver returns an empty allowed
  set now matches nothing instead of the whole fleet, and deploy-marker queries
  respect a multi-value lock (previously left unscoped).

### Fixed

- **Assets are exempt from the dashboard throttle.** The JS/CSS bundle sat
  behind the same `telemetry-ui.throttle` (120/min per IP) as the pages, so
  a busy dashboard — auto-refresh, several tabs, a shared office IP — could
  429 the bundle itself and take every chart down
  (`telemetryUiChart is not defined`). Version-stamped immutable assets now
  skip the throttle, the same way they already skip the auth gate.
- **The unified Errors card now works against real data.** It searched Tempo
  for `span.exception.group`, but laravel-telemetry records backend exceptions
  as span *events* (and as structured log records) — the attribute never
  exists on spans, so the card was permanently empty in production. It now
  reads the structured exception records from Loki (authoritative — present
  even when the trace is sampled away) and merges in browser exception spans
  from Tempo, fingerprinted read-side with the same algorithm the backend
  uses (browser ingest doesn't stamp `exception.group`).
- Deploy-marker annotations are fetched in a single Loki query instead of one
  per marker type (6+ round trips) — much cheaper on every chart card.
- `TelemetryUi::setCards()` / `removeCard()` now affect the **dashboard** page's
  config-declared cards (they previously no-op'd there).
- A misconfigured repo in a multi-repo `connections.issues` list is skipped
  instead of 500-ing the Issues page; a partial per-tracker failure shows a
  warning banner rather than silently dropping that repo.
- Per-tenant connection config is keyed safely (no fatal when a resolver returns
  a closure/resource), memoised per request, and `hasIssues()` is resolver-aware
  so page/gate decisions match the tracker actually resolved.
- The brand accent value can no longer smuggle an external `url(...)`, and an
  array-shaped `?service[]=` no longer corrupts the `DashboardViewed` audit scope.

### Added

- **Integration events** — `DashboardViewed` (user + page + scope, for audit and
  usage metering) and `BackendQueried` (url + method + duration + ok, for backend
  load metering per tenant) let a host hook the dashboard without patching it.
- **Branding / white-label** — `telemetry-ui.brand` config sets the sidebar
  name, logo and accent colour; views are namespaced (`telemetry-ui::`) so a
  host can override any of them.
- **Per-tenant backends** — `TelemetryUi::resolveConnectionsUsing(fn ($user) => [...])`
  resolves connection config per viewer, so a hosted multi-tenant install can
  point each tenant at their own Mimir/Tempo/Loki (or a shared backend behind a
  per-tenant `X-Scope-OrgID`). Omitted connections fall back to the static
  config; drivers are cached by config, so one tenant never gets another's under
  Octane. See [authorization](docs/core-concepts/authorization.md#per-tenant-backends).
- **Tenancy scope lock** — `TelemetryUi::restrictScopeUsing(fn ($user) => [...])`
  locks a viewer to a subset of services and/or environments, for embedding the
  dashboard in an app. The scope switcher only offers the allowed values and
  **every query is forced into the lock** server-side — a blank or hand-edited
  `?service=` can't widen past it (one allowed service → `service_name="x"`,
  several → a `service_name=~"a|b"` alternation), across metrics, traces and
  logs. Resolved per request. See the [authorization doc](docs/core-concepts/authorization.md#tenancy-lock-a-viewer-to-services--environments).

## [0.1.0-alpha.2] - 2026-07-05

### Added

- **Analytics page (visit analytics)** — a privacy-first traffic dashboard built
  on the emitter's unsampled `analytics.page_view` stream: a page-views trend
  chart (with deploy annotations), **unique visitors** (the cookieless daily
  session hash — no cookies, no stored IP), views-per-visit, **bounce rate**
  (single-page-view sessions) and **average engagement time** (from
  `analytics.engagement` events), top pages with distinct visitors, and a
  sources/audience
  breakdown (referrers, and — when the emitter's geo/User-Agent enrichment is on
  — countries and devices). Trace/Loki-sourced so it's exact for low-traffic
  sites and a bounded sample at scale (the eventual answer being a ClickHouse
  sink behind the same cards). Also: the Loki driver now surfaces per-entry
  **structured metadata**, so high-cardinality OTLP log attributes (the visit
  dimensions) are readable instead of dropped.
- **Frontend page (RUM)** — a new Monitoring page for real-user browser data:
  **Page performance** (navigation timings per page — loads, avg load, TTFB,
  DOM-interactive, from the `document.load` spans) and **Failed browser
  requests** (fetch/XHR calls that 5xx'd or errored, grouped by URL, each row
  opening a representative trace where a same-origin failure continues into the
  backend span that caused it). Trace-sourced (no RUM metric exists), bounded
  sample.
- **Unified errors list (frontend + backend)** — a new lead card on the
  Exceptions page groups every error by `exception.group`, the Sentry-style
  fingerprint (class + top in-app frame) that both the backend handler and the
  browser SDK stamp with the *same* algorithm. A JS `TypeError` and a PHP
  exception that are "the same issue" collapse into one row tagged
  `web`/`server`/`full-stack`, with an occurrence count and last-seen; clicking
  a row opens a representative trace (→ waterfall + host context), and "all"
  jumps to every trace for that fingerprint. Trace-sourced (metrics can't unify
  — frontend errors exist only as spans), so counts are over a bounded recent
  sample.
- **Frontend / RUM spans in the unified trace** — browser spans emitted by
  cboxdk/laravel-telemetry's frontend proxy (alpha.6/7) now read as first-class
  frontend rows. They share the backend's `service.name`, so the per-span
  server-stamped `browser=true` attribute is the marker: browser spans get a
  `web` badge in the waterfall, `document.load` shows its RUM timings (`TTFB`,
  `DOM`), and browser `fetch` spans render their URL + status. Trace search
  gains a **Source** filter (frontend/backend) that scopes on `span.browser`.
  Because the browser continues the backend's `traceparent`, a page load, its
  fetches and the server spans they trigger already nest into one waterfall —
  end-to-end frontend→backend on open data.

- **Dimensional drill-down / filtering (Grafana-style)** — every span/resource
  attribute in the trace properties window (host, user, team, client IP,
  deployment, method — whatever the app emits) is a click-to-filter link that
  scopes Traces to `{ .key = "value" }`. Plus a new **Hosts** page listing
  every host/server reporting telemetry (request volume, errors, CPU, memory),
  each row filtering requests to that host.
- **Purpose-built detail pages with progressive drill-down** — clicking a row
  opens a dedicated detail page instead of a pre-filtered trace search, à la
  Nightwatch, for **routes, jobs and exceptions**. Each shows the entity's own
  numbers scoped to it, and drills deeper: a route detail goes throughput →
  latency → *exact status codes* → its individual traces (→ waterfall + host
  context). Built on a "hidden page" concept (routable + rendered, out of the
  sidebar), a `scopeMatchers()` card hook and per-entity `ScopesTo*` traits, so
  the overview cards are reused scoped to one entity — the pattern extends to
  hosts, queries, etc. cheaply.
- **MCP server** — `php artisan mcp:start telemetry-ui` serves metrics, traces,
  logs and the correlation/analysis tools over the Model Context Protocol,
  built on the first-party `laravel/mcp` package, so an agent (Claude Desktop,
  Cursor, …) can query the stack directly for incident RCA. Six read-only
  `Server\Tool`s, including `trace_context` (a trace plus the host/runtime
  signals around it, flagged against normal). Same read drivers the dashboard
  uses.
- **Remote MCP over HTTP with OAuth + DCR** — set `TELEMETRY_UI_MCP_WEB=true`
  (and install `laravel/passport`) to expose the server over HTTP behind
  `auth:api`, with the OAuth 2.1 authorization server and **Dynamic Client
  Registration** endpoint that `laravel/mcp` provides — no custom OAuth code.
  Off by default; Passport stays optional.
- **Signal correlation** — a trace now shows the host and runtime signals
  recorded around it (CPU, load, memory, network, process RSS) in a context
  strip beside the waterfall, scoped by service + host and the trace's time
  window. This is the thing an app-only monitor can't do: the same Prometheus
  scrapes `system_*`/`process_*` — and node_exporter, mysqld_exporter, … when
  present — right next to the app. Config-driven and fail-open per signal
  (`telemetry-ui.context.signals`); a new headless `Analysis\SignalContext`
  is the reusable foundation.
- **"What was different"** — each context signal also carries its baseline (the
  typical value for that scope over a longer lookback), so a tile reads "Host
  CPU 95% (typical 30%)" and flags outliers. Answers the "was the box busted?"
  question at a glance, without ML — just an honest comparison to normal.

- `php artisan telemetry-ui:check` — probes each configured connection with its
  cheapest read and reports OK/FAIL/not-configured; exits non-zero on failure
  so it doubles as a deploy healthcheck.
- **Annotation writing** — `php artisan telemetry-ui:annotate <marker>` emits a
  marker (deploy, incident, scaling, migration, feature, version — or your own)
  through the telemetry pipeline into Loki, where it renders as a vertical line
  on every chart. No local state: the same store the dashboard already reads.
  `cboxdk/laravel-telemetry` is now a hard dependency (it provides the write
  path, and the dashboard instruments its own stack).
- **Proactive auto-version annotations** — `php artisan telemetry-ui:scan-versions`
  (schedule it) detects a `laravel_version` that's live in the metrics but
  un-annotated and marks it, so an un-announced deploy still lands on the
  charts. Stateless: it dedups against the version annotations already in Loki.
- Whole-row click targets on the routes, jobs, facet, slow-query, trace-search,
  outgoing and exceptions tables — the entire row drills into the matching
  traces (or opens the trace drawer / matching issues), not just the small
  link. cmd/ctrl-click opens in a new tab. Outgoing rows filter traces by
  `server.address`; exception rows jump to their matching issues (or the
  scoped error traces when no tracker is configured).

### Changed

- Dashboard cards now stream in (lazy `on-load`) instead of rendering eagerly,
  so the page shell paints instantly and a slow backend query on one card no
  longer blocks the whole page; each card loads in its own parallel request.

### Fixed

- Chart hover tooltips and drag-to-zoom both work now. The dataZoom brush was
  kept permanently armed for drag-select, which put every chart in select-mode
  and suppressed hover tooltips — so "no data on hover" and "zoom broken" were
  the same bug. Replaced with a raw zrender drag-select (own selection band),
  so hovering shows values (`trigger: 'axis'`) and dragging realigns the range.
- Linear now surfaces GraphQL errors (auth/permission/query failures, which
  Linear returns as HTTP 200 with an `errors` array) as a `SourceException`
  instead of silently returning an empty issue list.
- Prometheus/Mimir non-finite values: `NaN`/`+Inf`/`-Inf` (which Prometheus
  serializes as strings) were cast to a misleading `0.0`. They are now dropped
  so gauges/ratios show a gap instead of a false zero, and the `scalar`
  result branch no longer risks a raw `TypeError` past the `SourceException`
  boundary.
- Fleet (sidebar service/environment) cache TTL is now config-wired
  (`telemetry-ui.fleet.ttl` / `TELEMETRY_UI_FLEET_TTL`), matching the other
  cache TTLs.

### Changed

- `ConnectionManager::client()` is now public so custom drivers registered via
  `extend()` can reuse the configured `ApiClient` (auth, tenancy, cache,
  retries) instead of building one by hand.

### Security

- **Finer-grained authorization.** The `viewTelemetryUi` gate is now **re-run on
  Livewire updates** (card/drawer actions POST to `/livewire/update`, which
  previously skipped it — the gate was only enforced at page load). The gate
  also receives the **page slug**, so an app can restrict individual pages
  (e.g. the PII-heavy Logs/Users) without closing the whole dashboard — denied
  pages 403 and drop from the sidebar/palette. And a new **`manageTelemetryUi`**
  ability gates write actions (creating tracker issues), checked server-side and
  hiding the compose UI, so a read-only viewer can't file tickets (it falls back
  to the view gate, so existing setups are unchanged). See the new
  [authorization doc](docs/core-concepts/authorization.md).
- Backend failures no longer leak the internal endpoint, query string or raw
  response body to the dashboard. `SourceException` now carries a generic
  user-facing message and a separate detail; `ApiClient` logs the full detail
  server-side (the dashboard gate may be opened to semi-trusted operators).
- The MCP web transport throws at boot when OAuth is enabled but
  `laravel/passport` is absent, instead of registering a half-configured
  authorization server. `mcp.web.middleware` documents that `auth:api` is the
  only guard on that endpoint.
- MCP tools are bounded (row/series/window/limit caps + a dedicated throttle),
  `tagValues` lookups carry a time window + limit, and the annotation writer
  can no longer crash a command or the scan-versions cron on an emit failure.

### Documentation

- New [configuration reference](docs/core-concepts/configuration.md) (every key
  + env var), [signal correlation](docs/core-concepts/correlation.md) and
  [custom detail pages](docs/extension-points/detail-pages.md) guides; README
  and roadmap rewritten for the correlation/MCP/annotations/drill-down surface.

### Verified

- GitHub and Linear issue read **and** create paths exercised end-to-end
  against the live APIs. Sentry remains fixture-tested only — see the
  verification-status table in the issue-trackers docs.

## [0.1.0-alpha.1] - 2026-07-03

First alpha. A Livewire + ECharts observability dashboard querying Tempo
(TraceQL), Loki (LogQL) and Prometheus/Mimir (PromQL) directly — a companion
to `cboxdk/laravel-telemetry`.

### Added

- Connector layer with `MetricsSource`/`TracesSource`/`LogsSource`/`IssuesSource`
  contracts and Prometheus, Mimir, Tempo, Loki, GitHub, Sentry and Linear
  drivers, resolved lazily through a `ConnectionManager` with `extend()`.
- Full Nightwatch-inspired information architecture: dashboard, requests, jobs,
  commands, schedule, exceptions, queries, cache, outgoing, mail, users, logs,
  system and traces pages, with a service/environment scope switcher.
- Trace waterfall with infra-chain nesting, drag-to-zoom charts that adapt
  sampling, Loki-backed deploy annotations, facet views, sparklines, a command
  palette and stacked slide-in drawers.
- Issue trackers as a fourth signal, with create-a-ticket-from-an-exception
  for GitHub and Linear.
- Schema autodetection (e.g. the built-in Statamic page) via metric presence.
- Short-TTL query cache: decoded backend GET responses are cached for
  `telemetry-ui.cache.ttl` seconds (default 5, override per connection) so a
  busy dashboard with many cards and auto-refresh does not hammer
  Prometheus/Tempo/Loki. Only plain arrays are cached, never DTOs.
- Transient-blip retry on backend connections (`telemetry-ui.retries`).
- Rate limiting on the dashboard routes via `telemetry-ui.throttle`
  (default `120,1`).
- CI: run-tests (PHP 8.3–8.5 × Laravel 12/13, lowest/stable), PHPStan level 8
  and Pint workflows.
