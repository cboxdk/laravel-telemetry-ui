---
title: Authorization
description: The view gate, per-page restriction, the write ability, and the PII surface
weight: 7
---

# Authorization

The dashboard exposes traces, logs and metrics — which routinely contain PII
(user IDs, IP addresses, query text, request headers). Access control is
therefore load-bearing. There are two gates plus a per-page hook, all enforced
server-side and re-checked on every request, including every API call the
SPA makes.

## The view gate

Every dashboard route runs behind the `viewTelemetryUi` gate. **Out of the box
it allows only the `local` environment** — so a fresh install is closed
everywhere else. Open it up by redefining the gate in your app (app providers
boot after the package, so your definition wins):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTelemetryUi', fn ($user) => $user?->isAdmin() ?? false);
```

The gate runs on the SPA shell **and on every `/api/v2` request**. The SPA
fetches each panel, facet list and trace as its own API call, so revoking access
takes effect on the next request, not on the next full page load. A denied API
call answers `403` with `{"error":{"type":"forbidden",…}}`.

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

A denied page returns `403` from its page and panel endpoints **and** is dropped
from the navigation the bootstrap endpoint returns (sidebar and command
palette). The other endpoints check the page that covers their data:

| Endpoint | Page checked |
| --- | --- |
| `explore/requests`, `facets/requests`, `stream/requests`, `entities/*` | `requests` |
| `explore/traces`, `facets/traces`, `traces/{id}` | `traces` |
| `explore/logs`, `facets/logs`, `stream/logs` | `logs` |
| `explore/errors`, `facets/errors`, `errors/{group}` | `exceptions` |
| `panels/{panel}` | any page the panel is registered on |

The slug is `null` for the master check that runs on every route, so a
page-unaware gate keeps working.

## The write ability

Creating a tracker issue from the UI (the compose-a-ticket flow) is a write to
an external system, so it needs a separate ability: **`manageTelemetryUi`**. It
is checked server-side on `POST /api/v2/issues`, and the compose UI is hidden
without it (the bootstrap endpoint reports `abilities.createIssues`) — so a
read-only viewer can look but not file tickets.

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
The API routes share that middleware stack, so the SPA's requests are
authenticated the same way.

## Tenancy: lock a viewer to services / environments

When the dashboard runs inside a multi-tenant app, you often want a viewer to see only
their own service(s) — a lightweight tenancy lock. There are two ways to set it,
in precedence order.

**1. Dynamic, per user — a code hook** (the right tool for real multi-tenancy).
Register a resolver that returns the allowed services and/or environments for the
current user:

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

TelemetryUi::restrictScopeUsing(fn ($user) => [
    'services' => $user->allowedServices(),   // e.g. ['cbox-web']
    'environments' => ['production'],          // omit the key to leave envs open
]);
```

The resolver receives the authenticated user and runs per request (request-scoped,
so nothing leaks between users under Octane).

**2. Static, no code — config / env.** For a fixed lock (one app or tenant), set
it in `config/telemetry-ui.php`:

```php
'scope' => [
    'lock' => [
        'services' => ['cbox-web'],
        'environments' => ['production'],
    ],
],
```

or via env (comma-separated):

```dotenv
TELEMETRY_UI_LOCK_SERVICES=cbox-web
TELEMETRY_UI_LOCK_ENVIRONMENTS=production
```

The `restrictScopeUsing` hook, when set, **takes precedence** over the config
lock.

**Semantics** (same for both), per dimension:

- a **value present** locks it — `['cbox-web']` to that set, or `[]` to
  *nothing* (matches nothing — a hard fail-closed lock, **not** "all");
- **absent / `null`** leaves it open.

With a lock in place:

- The **picker only offers the allowed values** (the discovered fleet is
  intersected with the lock), and it **drops the "All" option**. A dimension
  locked to a single value has no choice to make, so its picker is **hidden
  entirely**.
- **Every query is forced into the lock** — a blank `?service=` (which normally
  means "all services"), a hand-edited `?service=someone-else`, and a raw
  deep-linked TraceQL `?q=` are all coerced back to the allowed set, across
  metrics, traces and logs. A single allowed service scopes to
  `service_name="x"`; several scope to a `service_name=~"a|b"` alternation.

It's enforced server-side in the query scope, not just the UI, so it can't be
bypassed from the URL. That includes a trace opened by id (`/traces/{id}`): a
trace with a service outside the lock, or — with environments locked — a
service that ran in another environment or does not say which, is answered as
not found.

#### The label names the lock filters by

The lock (and the service/environment pickers) filter by the names
`cboxdk/laravel-telemetry` emits: `service_name` / `deployment_environment_name`
/ `host_name` in Prometheus and Loki, `resource.service.name` /
`resource.deployment.environment.name` / `resource.host.name` in TraceQL. When
your telemetry comes from elsewhere — a Prometheus or Alloy scrape that stamps
`environment` and `hostname` as external labels, or an eBPF agent such as Beyla
that sends the older `deployment.environment` attribute — set the names per
backend, or every locked query matches nothing:

```php
// config/telemetry-ui.php
'scope' => [
    'lock' => [/* … */],
    'labels' => [
        'metrics' => ['service' => 'service_name', 'environment' => 'environment', 'host' => 'hostname'],
        'traces' => ['service' => 'resource.service.name', 'environment' => 'resource.deployment.environment', 'host' => 'resource.host.name'],
        'logs' => ['service' => 'service_name', 'environment' => 'environment', 'host' => 'hostname'],
    ],
],
```

Each has an env override (`TELEMETRY_UI_METRICS_ENVIRONMENT_LABEL`,
`TELEMETRY_UI_TRACES_ENVIRONMENT_ATTRIBUTE`, `TELEMETRY_UI_LOGS_HOST_LABEL`, …).
A name left out keeps its default.

> Note: chart **deploy-marker annotations** are scoped when the effective scope
> is a single service; a multi-service lock leaves the markers unscoped (they
> reveal only deploy *timestamps*, not telemetry). The MCP transport is a
> separate surface and is not covered by the scope lock.

### Per-tenant backends

The scope lock partitions viewers within *one* backend. If instead each tenant
has their **own** backend — or shares one behind a per-tenant `X-Scope-OrgID` —
resolve the connection config per viewer:

```php
TelemetryUi::resolveConnectionsUsing(fn ($user) => [
    'metrics' => ['driver' => 'mimir', 'url' => $user->tenant->mimir_url, 'tenant' => $user->tenant->id],
    'traces'  => ['driver' => 'tempo', 'url' => $user->tenant->tempo_url, 'tenant' => $user->tenant->id],
    'logs'    => ['driver' => 'loki',  'url' => $user->tenant->loki_url,  'tenant' => $user->tenant->id],
]);
```

The resolver receives the authenticated user; any connection it omits falls back
to the static `telemetry-ui.connections` config. It runs per request, and built
drivers are cached by config (not just name), so under a persistent runtime like
Octane one tenant never gets another's connection.

## MCP

The HTTP MCP transport is a separate surface with its own auth (`auth:api` +
throttle, optionally OAuth) — see the [MCP cookbook](../cookbook/mcp.md). It is
not covered by `viewTelemetryUi`.

## What the gate does *not* do

The gate is all-or-nothing per page; it is **not** field-level redaction. The
dashboard renders whatever the backend returns, so a viewer sees every attribute
on a trace or log line. Redaction is the emitter's job (`cboxdk/laravel-telemetry`
redacts at collection time) — scope who can reach the dashboard accordingly, and
lean on per-page restriction for the most sensitive screens.
