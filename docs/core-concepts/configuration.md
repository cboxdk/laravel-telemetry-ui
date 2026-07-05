---
title: Configuration reference
description: Every config key and environment variable in config/telemetry-ui.php
weight: 3
---

# Configuration reference

The package ships a fully-commented `config/telemetry-ui.php`. It works out of
the box with three env vars (metrics/traces/logs URLs); everything else has a
sensible default. Publish it only if you need to change something not reachable
by an env var:

```bash
php artisan vendor:publish --tag=telemetry-ui-config
```

This page is the exhaustive reference. Each key lists its default and the env
var (if any) that overrides it without publishing.

## Core

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `enabled` | `TELEMETRY_UI_ENABLED` | `true` | Master switch. When off, the package registers **no** routes, Livewire components or MCP server — completely inert (e.g. on queue workers). Boot stays cheap either way. |
| `path` | `TELEMETRY_UI_PATH` | `telemetry-ui` | URL prefix the dashboard is served from. Deliberately not `telemetry` so it doesn't clash with the `/telemetry/metrics` scrape endpoint `cboxdk/laravel-telemetry` registers. |
| `domain` | `TELEMETRY_UI_DOMAIN` | `null` | Optional domain to pin the routes to. |
| `middleware` | — | `['web']` | Middleware on every dashboard route. The package **always** appends its own `Authorize` middleware (checks the `viewTelemetryUi` gate), so you don't list it. |
| `throttle` | `TELEMETRY_UI_THROTTLE` | `120,1` | Rate limit as `maxAttempts,decayMinutes`. The dashboard fans out to all backends on every render and refresh tick, so this caps how hard one client can drive them. Set to `null` / empty to disable. |

### The gate

Access is governed by the `viewTelemetryUi` gate. The default definition
allows access **only in the `local` environment**; open it up in your own
`AppServiceProvider` (app providers boot after the package, so your definition
wins):

```php
Gate::define('viewTelemetryUi', fn ($user) => $user?->isAdmin() ?? false);
```

The gate also supports per-page restriction and a separate write ability
(`manageTelemetryUi`) — see [authorization](authorization.md).

## Query cache & retries

Every card issues live backend queries on each render and refresh tick.

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `cache.ttl` | `TELEMETRY_UI_CACHE_TTL` | `5` | Seconds to cache **decoded GET responses** (plain arrays — never DTOs, so it's safe on any store). Keep it short so data stays fresh; `0` disables. A connection may set its own `cache` to override. |
| `retries` | `TELEMETRY_UI_RETRIES` | `2` | Retries for a transient connection blip before giving up. |

Backend failures are never cached, and their full detail (URL, response body)
is logged server-side while the dashboard shows only a generic message — see
[connections](connections.md).

## Connections

Named connections to your backends live under `connections`. The keys
`metrics`, `traces` and `logs` are the defaults; add more named connections and
request them explicitly (e.g. a per-tenant Mimir). Drivers: `prometheus` /
`mimir` (metrics), `tempo` (traces), `loki` (logs). See
[connections](connections.md) and the [Grafana proxy
recipe](../cookbook/connect-via-grafana-proxy.md) for the full story; the
env-var surface is:

| Connection | URL env | Token env | Tenant env | Extras |
| --- | --- | --- | --- | --- |
| metrics | `TELEMETRY_UI_METRICS_URL` | `TELEMETRY_UI_METRICS_TOKEN` (falls back to `TELEMETRY_UI_TOKEN`) | `TELEMETRY_UI_METRICS_TENANT` | `TELEMETRY_UI_METRICS_DRIVER`, `TELEMETRY_UI_METRICS_PREFIX`, `TELEMETRY_UI_METRICS_BASIC_AUTH`, `TELEMETRY_UI_METRICS_TIMEOUT` |
| traces | `TELEMETRY_UI_TEMPO_URL` | `TELEMETRY_UI_TEMPO_TOKEN` (→ `TELEMETRY_UI_TOKEN`) | `TELEMETRY_UI_TEMPO_TENANT` | `TELEMETRY_UI_TEMPO_BASIC_AUTH`, `TELEMETRY_UI_TEMPO_TIMEOUT` |
| logs | `TELEMETRY_UI_LOKI_URL` | `TELEMETRY_UI_LOKI_TOKEN` (→ `TELEMETRY_UI_TOKEN`) | `TELEMETRY_UI_LOKI_TENANT` | `TELEMETRY_UI_LOKI_BASIC_AUTH`, `TELEMETRY_UI_LOKI_TIMEOUT` |

- **`tenant`** sets the `X-Scope-OrgID` header (multi-tenant Mimir/Tempo/Loki).
- **`token`** becomes a Bearer `Authorization` header; **`basic_auth`** (`user:pass`)
  becomes a Basic one. Add arbitrary headers under a connection's `headers`.
- **`prefix`** (metrics) is the API path prefix — `mimir` is just `prometheus`
  under a prefix (default `/prometheus`).

### Issue tracker (optional)

Setting `connections.issues.driver` adds an **Issues** page. Disabled by
default. See [issue trackers](../extension-points/issue-trackers.md).

| Key | Env |
| --- | --- |
| `connections.issues.driver` | `TELEMETRY_UI_ISSUES_DRIVER` (`github` / `sentry` / `linear`) |
| `connections.issues.repo` | `TELEMETRY_UI_GITHUB_REPO` |
| `connections.issues.url` | `TELEMETRY_UI_ISSUES_URL` |
| `connections.issues.token` | `TELEMETRY_UI_ISSUES_TOKEN` |

## Discovery caches

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `detection.ttl` | `TELEMETRY_UI_DETECTION_TTL` | `300` | Seconds to cache the one probe query that decides whether a metric-detected page (e.g. the Statamic page) is visible. |
| `fleet.ttl` | `TELEMETRY_UI_FLEET_TTL` | `60` | Seconds to cache the service/environment list behind the sidebar scope switcher. |

## Signal context (correlation)

Correlates a trace with the host/runtime signals recorded around it. Full
treatment in [correlation](correlation.md).

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `context.enabled` | `TELEMETRY_UI_CONTEXT` | `true` | Show the context strip beside the trace waterfall. |
| `context.window` | `TELEMETRY_UI_CONTEXT_WINDOW` | `600` | Seconds padded around a trace so surrounding metric samples land in view. |
| `context.baseline_window` | `TELEMETRY_UI_CONTEXT_BASELINE` | `21600` | Lookback (seconds) for each signal's "typical" value — the number the tile flags against ("95%, typical 30%"). |
| `context.baseline_ttl` | `TELEMETRY_UI_CONTEXT_BASELINE_TTL` | `120` | How long a computed baseline is cached. Baselines are multi-hour averages that barely move, so this is well beyond the live cache and shared across nearby traces. |
| `context.signals` | — | 5 built-ins | The signal list — each a `label` / `group` / `unit` / PromQL `query` with a `{scope}` token. See [correlation](correlation.md) to add your own. |

## MCP server

The local stdio server (`php artisan mcp:start telemetry-ui`) always works.
The keys below only gate the optional HTTP transport. See the [MCP
cookbook](../cookbook/mcp.md).

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `mcp.web.enabled` | `TELEMETRY_UI_MCP_WEB` | `false` | Expose the server over HTTP. |
| `mcp.web.path` | `TELEMETRY_UI_MCP_PATH` | `telemetry-ui/mcp` | HTTP endpoint path. |
| `mcp.web.middleware` | — | `['auth:api', 'throttle:60,1']` | The **only** auth on this endpoint (the dashboard gate does not cover it). Keep an auth guard here. |
| `mcp.web.oauth` | `TELEMETRY_UI_MCP_OAUTH` | `true` | Register the OAuth 2.1 + Dynamic Client Registration endpoints `laravel/mcp` provides on top of `laravel/passport`. **If this is `true` and Passport is not installed, the app throws at boot** rather than expose a half-configured authorization server — install Passport, or set this to `false` and front the endpoint with your own auth middleware. |

## Annotations

Point-in-time markers drawn as vertical lines on every chart. Full treatment in
the [annotations cookbook](../cookbook/annotations.md).

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `annotations.enabled` | `TELEMETRY_UI_ANNOTATIONS` | `true` | Read + draw annotations. |
| `annotations.ttl` | `TELEMETRY_UI_ANNOTATIONS_TTL` | `30` | Seconds to cache the annotation read. |
| `annotations.markers` | — | 6 built-ins | Map of marker key → `{ event, label, color, id_label, notes_label }`. Each is both read (matched in Loki by `event`) and written (`php artisan telemetry-ui:annotate <key>`). Add your own. |
| `annotations.auto_version.enabled` | `TELEMETRY_UI_AUTO_VERSION` | `false` | Let `telemetry-ui:scan-versions` auto-annotate a newly-seen `laravel_version`. |
| `annotations.auto_version.metric` | `TELEMETRY_UI_AUTO_VERSION_METRIC` | `system_cpu_utilization_ratio` | The metric carrying the `laravel_version` label to scan. |
| `annotations.auto_version.lookback_days` | `TELEMETRY_UI_AUTO_VERSION_LOOKBACK` | `30` | How far back the scan looks for versions. |

## Cards

`cards` is the ordered list of dashboard cards (Livewire components extending
`Cbox\TelemetryUi\Cards\Card`). Entries here render first; packages append their
own at runtime with `TelemetryUi::card(MyCard::class)`. See [pages &
cards](pages-and-cards.md).
