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
