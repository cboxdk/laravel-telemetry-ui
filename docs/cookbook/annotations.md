---
title: Emitting annotations
description: Mark deploys, incidents, scaling and more as vertical lines on every chart
weight: 2
---

# Emitting annotations

Annotations are point-in-time markers drawn on every chart — the way you map a
latency regression to the deploy that caused it. The dashboard **reads** them
from your logs backend (Loki) and **writes** them through the telemetry
pipeline, so there's no separate store: an annotation is just an event next to
your traces and metrics.

Because writing needs the emitter, the UI depends on
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) — it's
a hard dependency, which also means the dashboard instruments its own stack.

## Writing a marker

```bash
php artisan telemetry-ui:annotate deploy    --id="$(git rev-parse --short HEAD)"
php artisan telemetry-ui:annotate incident  --notes="checkout 5xx spike"
php artisan telemetry-ui:annotate scaling   --id=web --notes="+2 workers"
php artisan telemetry-ui:annotate migration --notes="add orders.shipped_at"
php artisan telemetry-ui:annotate feature   --id=new-checkout --notes="rolled to 50%"
```

Run these from wherever the event happens — a Forge/Envoyer deploy hook, a CI
step, your autoscaler, a migration runner, a feature-flag webhook. The marker
lands in Loki and shows up on every panel, colour-coded by type.

If telemetry is disabled the command is a no-op (it won't fail your deploy).

## Marker types

Five ship by default; each is both read (matched in Loki by its `event`) and
writable by its key:

| Key | Event | Colour |
| --- | --- | --- |
| `deploy` | `app.deployment` | purple |
| `incident` | `app.incident` | red |
| `scaling` | `app.scaling` | blue |
| `migration` | `app.migration` | green |
| `feature` | `app.feature_flag` | amber |

## Adding your own

Add an entry under `telemetry-ui.annotations.markers`:

```php
'markers' => [
    'maintenance' => [
        'event' => 'app.maintenance',
        'label' => 'Maintenance',
        'color' => '#94a3b8',
        'id_label' => 'maintenance_id',       // Loki label read back
        'notes_label' => 'maintenance_notes',
    ],
],
```

`telemetry-ui:annotate maintenance --notes="db failover"` now works, and the
marker renders. The `id_label`/`notes_label` are the Loki labels the reader
matches; the writer emits them as the dotted OTLP attributes
(`maintenance.id`, `maintenance.notes`) the emitter flattens back.
