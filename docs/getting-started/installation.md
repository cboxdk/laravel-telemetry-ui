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

## Disabling entirely

`TELEMETRY_UI_ENABLED=false` makes the package inert: no routes, no gate, no
Livewire registrations. Useful for queue workers and environments where the
dashboard should not exist.
