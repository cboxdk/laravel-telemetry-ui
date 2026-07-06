---
title: Core concepts
description: How the dashboard connects to your backends, scopes queries, renders screens and links signals
weight: 20
---

# Core concepts

The ideas the whole dashboard is built on — read these once and the rest of the
docs (and the code) fall into place.

- [Connections](connections.md) — named Tempo / Loki / Prometheus/Mimir
  connections, multi-tenancy and the datasource-proxy setup.
- [Pages & cards](pages-and-cards.md) — every screen is a registry of Livewire
  cards; how pages are declared and autodetected.
- [Configuration reference](configuration.md) — every config key, annotated.
- [Signal correlation](correlation.md) — how a route links to its traces, a
  trace to its logs, an exception to its issue.
- [Authorization](authorization.md) — the `viewTelemetryUi` gate and the
  read/write ability split.
