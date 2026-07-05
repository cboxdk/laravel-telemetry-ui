---
title: Authorization
description: The view gate, per-page restriction, the write ability, and the PII surface
weight: 5
---

# Authorization

The dashboard exposes traces, logs and metrics — which routinely contain PII
(user IDs, IP addresses, query text, request headers). Access control is
therefore load-bearing. There are two gates plus a per-page hook, all enforced
server-side and re-checked on every request (including Livewire updates).

## The view gate

Every dashboard route runs behind the `viewTelemetryUi` gate. **Out of the box
it allows only the `local` environment** — so a fresh install is closed
everywhere else. Open it up by redefining the gate in your app (app providers
boot after the package, so your definition wins):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTelemetryUi', fn ($user) => $user?->isAdmin() ?? false);
```

The gate is also **re-run on Livewire updates**, not just the initial page
load. The cards and the trace drawer are Livewire components; their actions POST
to `/livewire/update`, which the package registers the gate middleware against —
so revoking access takes effect immediately, not only on the next full render.

## Restricting individual pages

The gate receives the **page slug** as a second argument, so you can allow the
dashboard but hide specific pages — e.g. keep the PII-heavy **Logs** and
**Users** pages for admins while letting the wider team see performance:

```php
Gate::define('viewTelemetryUi', function ($user, ?string $page = null) {
    if (in_array($page, ['logs', 'users'], true)) {
        return $user?->isAdmin() ?? false;
    }

    return $user !== null; // any authenticated user sees the rest
});
```

A denied page returns `403` on direct access **and** is dropped from the sidebar
and command palette. The slug is `null` for Livewire updates and non-page routes
(only the master check applies there), so a page-unaware gate keeps working.

## The write ability

Creating a tracker issue from the UI (the compose-a-ticket flow) is a write to
an external system, so it needs a separate ability: **`manageTelemetryUi`**. It
is checked server-side before the issue is created, and the compose UI is hidden
without it — so a read-only viewer can look but not file tickets.

By default it **falls back to the view gate** (anyone who can view can write),
which preserves existing behaviour. Define it to split read from write:

```php
Gate::define('manageTelemetryUi', fn ($user) => $user?->isAdmin() ?? false);
```

## Authenticating with an existing panel (e.g. Statamic CP)

The route middleware stack is config-driven (`telemetry-ui.middleware`, with the
gate always appended), so you can front the dashboard with any auth middleware
instead of plain `web`. To sign in with **Statamic control-panel users**, run it
through Statamic's CP groups — unauthenticated visitors are redirected to the CP
login:

```php
// config/telemetry-ui.php
'middleware' => ['statamic.cp', 'statamic.cp.authenticated'],
```

Use **both** groups: `statamic.cp` carries session/CSRF/bindings, and
`statamic.cp.authenticated` adds the authenticate + authorize layer. Then scope
the gate to a Statamic permission:

```php
Gate::define('viewTelemetryUi', fn ($user) => (bool) $user?->can('access cp'));
// or restrict to supers — $user?->isSuper() — or a custom permission.
```

The same works for any guarded panel (Filament, Nova, a custom admin): put its
auth middleware in `telemetry-ui.middleware` and check the user in the gate.
Livewire updates keep working — they run on the global `web` group, and the gate
is still re-checked on them.

## Tenancy: lock a viewer to services / environments

When the dashboard is embedded in an app, you often want a viewer to see only
their own service(s) — a lightweight tenancy lock. Register a resolver that
returns the allowed services and/or environments for the current user:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

TelemetryUi::restrictScopeUsing(fn ($user) => [
    'services' => $user->allowedServices(),   // e.g. ['cbox-web']
    'environments' => ['production'],          // optional; omit/[] = all envs
]);
```

An empty or absent key means *unrestricted* for that dimension. With a lock in
place:

- The **scope switcher only offers the allowed values** (the discovered fleet is
  intersected with the lock).
- **Every query is forced into the lock** — a blank `?service=` (which normally
  means "all services") and a hand-edited `?service=someone-else` are both
  coerced back to the allowed set, across metrics, traces and logs. A single
  allowed service scopes to `service_name="x"`; several scope to a
  `service_name=~"a|b"` alternation.

The resolver runs per request (request-scoped, so nothing leaks between users
under Octane). It's enforced server-side in the query scope, not just the UI, so
it can't be bypassed from the URL.

> Note: chart **deploy-marker annotations** are scoped when the effective scope
> is a single service; a multi-service lock leaves the markers unscoped (they
> reveal only deploy *timestamps*, not telemetry). The MCP transport is a
> separate surface and is not covered by the scope lock.

## MCP and the API

The HTTP MCP transport is a separate surface with its own auth (`auth:api` +
throttle, optionally OAuth) — see the [MCP cookbook](../cookbook/mcp.md). It is
not covered by `viewTelemetryUi`.

## What the gate does *not* do

The gate is all-or-nothing per page; it is **not** field-level redaction. The
dashboard renders whatever the backend returns, so a viewer sees every attribute
on a trace or log line. Redaction is the emitter's job (`cboxdk/laravel-telemetry`
redacts at collection time) — scope who can reach the dashboard accordingly, and
lean on per-page restriction for the most sensitive screens.
