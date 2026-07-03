---
title: Installation
description: Install the dashboard and point it at Tempo, Loki and Prometheus/Mimir
weight: 1
---

# Installation

```bash
composer require cboxdk/laravel-telemetry-ui
```

The package auto-registers. Point it at your backends:

```dotenv
TELEMETRY_UI_METRICS_URL=http://prometheus:9090
TELEMETRY_UI_TEMPO_URL=http://tempo:3200
TELEMETRY_UI_LOKI_URL=http://loki:3100
```

Using **Mimir** instead of vanilla Prometheus:

```dotenv
TELEMETRY_UI_METRICS_DRIVER=mimir
TELEMETRY_UI_METRICS_URL=http://mimir:8080
TELEMETRY_UI_METRICS_TENANT=team-apps   # X-Scope-OrgID
```

Visit `/telemetry-ui`. Assets (ECharts bundle + CSS) are served by the
package itself — no publishing, no npm step.

Confirm the config actually reaches every backend:

```bash
php artisan telemetry-ui:check
```

It probes each configured connection and reports `OK` / `FAIL`, exiting
non-zero on failure — handy as a post-deploy healthcheck.

## Authorization

Access is controlled by the `viewTelemetryUi` gate. Out of the box it only
allows the `local` environment. Open it up in a service provider:

```php
Gate::define('viewTelemetryUi', fn (User $user): bool => $user->isDeveloper());
```

## Publishing the config

```bash
php artisan vendor:publish --tag=telemetry-ui-config
```

See [connections](../core-concepts/connections.md) for the full config
reference, including multiple named connections and per-connection headers.

## Tuning

Sensible defaults ship for all of these; override via env when needed:

| Env var | Default | What it does |
| --- | --- | --- |
| `TELEMETRY_UI_CACHE_TTL` | `5` | Seconds to cache backend GET responses, so a busy dashboard doesn't hammer the backends. `0` disables. |
| `TELEMETRY_UI_RETRIES` | `2` | Retries for transient connection blips. |
| `TELEMETRY_UI_THROTTLE` | `120,1` | Rate limit for the dashboard routes (`maxAttempts,decayMinutes`). Set empty to disable. |
| `TELEMETRY_UI_DETECTION_TTL` | `300` | Seconds to cache schema autodetection probes. |
| `TELEMETRY_UI_FLEET_TTL` | `60` | Seconds to cache the sidebar service/environment discovery. |
| `TELEMETRY_UI_ANNOTATIONS` | `true` | Draw deploy/annotation markers on charts. |
| `TELEMETRY_UI_ANNOTATIONS_TTL` | `30` | Seconds to cache annotation lookups. |

A connection may also set its own `cache` key to override the global TTL. See
[connections](../core-concepts/connections.md) for the full reference.

## Disabling entirely

`TELEMETRY_UI_ENABLED=false` makes the package inert: no routes, no gate, no
Livewire registrations. Useful for queue workers and environments where the
dashboard should not exist.
