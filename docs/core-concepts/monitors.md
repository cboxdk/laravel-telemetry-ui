---
title: Monitors
description: Record one route, user, tenant or job in full for a while, from the dashboard or an agent, on top of laravel-telemetry's directives
weight: 30
---

# Monitors

A sample rate is right for the fleet and no help with the one customer on the
phone. A **monitor** turns recording up for one slice of a service's traffic
and turns itself off again. For 15 minutes, an hour, 4 hours or a day, every
matching trace is **kept whatever the sample rate said**, keeps its **detail
spans**, and can get a **CPU profile**. It works like Telescope's monitored
tags, built on cboxdk/laravel-telemetry's
[directives](https://github.com/cboxdk/laravel-telemetry/blob/main/docs/core-concepts/traces.md) (3.3+).
Every span recorded because of one carries `telemetry.directive = <id>`.

| Match | From | The core matches |
|---|---|---|
| route | a route value (`http.route`) | the route name or URI template, `*` wildcards |
| path | a path value (`url.path`) | the request path, no leading slash |
| user | a user value (`user.id`) | exactly that user |
| context | a declared dimension (e.g. `tenant.id`) | exactly that `Telemetry::context()` value |
| job | a job value (`laravel.job.class`) | the job class, `*` wildcards |

## Starting one

- **From a value.** Every value menu (the popover on a route, user, path,
  job or declared-dimension value, in tables, facets, chips and entity pages)
  has **Monitor…**.
- **From a trace.** The trace drawer's header has **Monitor…**, offering the
  trace's user, route and declared dimensions.

Pick the service (the one in scope is preselected), the duration
(15m / 1h / 4h / 24h), whether to capture a CPU profile, and a reason. The
monitor records you as its creator: your name and email from the dashboard's
user.

**Monitors** (under Explore) lists the active ones on every service you can
reach. Each row shows what it matches, a countdown to expiry, the reason, who
placed it, a link to the traces it has recorded so far, and a **Stop** button.
On Requests and Traces, **Recorded by monitor** filters to traces any monitor
kept (`telemetry.directive=~.+`). A trace a monitor kept says so in its
header.

## Which services

A service is monitorable in one of two ways:

1. **In-process.** This app runs cboxdk/laravel-telemetry with directives on,
   and the service is this app (`telemetry.service.name`). The dashboard calls
   `Telemetry::directives()` directly: no network, no secret. Turn it off with
   `TELEMETRY_UI_MONITORS_LOCAL=false`.
2. **Over signed HTTP.** For an app elsewhere, set
   `TELEMETRY_DIRECTIVES_SECRET` in that app, which turns on its
   `/telemetry/directives` endpoint. Then give the dashboard the same secret:

   ```php
   // config/telemetry-ui.php
   'monitors' => [
       'services' => [
           'shop' => [
               'url' => 'https://shop.example',
               'secret' => env('SHOP_DIRECTIVES_SECRET'),
               'environment' => 'production', // for environment-locked viewers
               // 'path' => 'telemetry/directives',
           ],
       ],
   ],
   ```

   Every request is signed: `X-Telemetry-Timestamp: <unix seconds>` and
   `X-Telemetry-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">`.
   GET and DELETE sign an empty body. The app allows five minutes of clock
   skew, so keep both clocks in sync.

No service is contacted at boot or to render the bootstrap. A service that
cannot be read is reported on the Monitors page, and the other services are
still listed.

## Who may do what

- **Placing and stopping** a monitor needs `manageTelemetryUi` (see
  [Authorization](authorization.md)). Without it, *Monitor…* is not offered
  and the API answers 403.
- **Listing** follows the view gate for the `monitors` page
  (`Gate::allows('viewTelemetryUi', ['monitors'])`).

### The scope rule

A monitor records traffic, so the viewer's scope lock applies to placing,
listing and stopping alike:

1. **Services.** You can only monitor services your lock allows.
2. **Environments.** With an environment lock, a target is offered only when
   its `environment` is one you may see. A target that does not declare an
   environment is not offered, because the lock fails closed.
3. **Tenants.** A resolver can confine a viewer to context values:

   ```php
   TelemetryUi::restrictScopeUsing(fn ($user) => [
       'services' => ['shop'],
       'contexts' => ['tenant.id' => [$user->tenant_id]],
   ]);
   ```

   Such a viewer may only place **context** monitors on those exact values.
   A route, path, job or user monitor is **refused**, because a directive
   matches across every tenant. "checkout.* for tenant 7" cannot be
   expressed, and "checkout.*" would record every other tenant's traffic. The
   viewer sees only the monitors it could have placed and can stop only
   those. Guessing another monitor's id answers 404.

`contexts` currently governs monitors only. Queries are scoped by service and
environment, as before.

The dashboard also refuses what the core would refuse, before calling it: an
unknown match, a context monitor with no key, a pattern that matches
everything (`*`), and a duration outside 1–1440 minutes. The core then applies
its own `directives.max` and `directives.max_minutes`.

## Over MCP

- `list_monitors` (read-only) returns the active monitors with what they
  match, minutes left, profile, reason and creator.
- `monitor` places one, or stops one with `remove = <id>`. It returns the
  monitor id and the TraceQL that finds its traces. It is a write tool, so it
  is **registered only when `TELEMETRY_UI_MCP_WRITE=true`** (off by default).
  Over HTTP it also needs `manageTelemetryUi`. The stdio server runs as the
  operator who started it. The scope rule applies as above, and the creator
  is the authenticated user, or `mcp`.

## API

| Method | Path | |
|---|---|---|
| GET | `/api/v2/monitors` | `{services, monitors, errors, now, canManage}`. `now` is the server clock, for countdowns. |
| POST | `/api/v2/monitors` | `{service, match, value, key?, minutes, profile, reason}` → 201 `{monitor}` |
| DELETE | `/api/v2/monitors/{service}/{id}` | → `{removed}` |
