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
- [Pages & panels](pages-and-panels.md) — pages are registries of panels,
  plain PHP classes returning a typed payload; how pages are declared and
  autodetected.
- [Dimensions & Explore](dimensions-and-explore.md) — declared dimensions, the
  filter syntax, facets, group-by and entity pages.
- [JSON API](api.md) — the `/api/v2` endpoints, scope params and error format.
- [Configuration reference](configuration.md) — every config key, annotated.
- [Signal correlation](correlation.md) — how a route links to its traces, a
  trace to its logs, an exception to its issue.
- [Authorization](authorization.md) — the `viewTelemetryUi` gate and the
  read/write ability split.
