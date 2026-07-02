---
title: "ADR 0002: Query Tempo/Loki/Mimir directly, no own storage"
description: The dashboard is a stateless query layer over the recommended telemetry stack
weight: 2
---

# ADR 0002: Query Tempo/Loki/Mimir directly, no own storage

**Status:** accepted (2026-07-03)

## Context

`cboxdk/laravel-telemetry` recommends an OTLP + Prometheus stack (Tempo,
Loki, Prometheus/Mimir). Nightwatch-style products instead ingest into their
own storage, which means agents, duplication and retention policy owned by
the tool.

## Decision

This package stores nothing. It is a stateless query layer speaking PromQL,
TraceQL and LogQL against the backends the apps already export to, through
three narrow contracts (`MetricsSource`, `TracesSource`, `LogsSource`) with
lazy, named, multi-tenant-aware connections.

## Consequences

- Zero ingestion infrastructure; retention/cost stay in the Grafana stack.
- The UI works for every service exporting to the same backends, not just
  the app it is installed in — fleet view for free.
- Latency of every screen is bounded by backend query speed; a short-TTL
  cache layer (Laravel cache) is the planned mitigation, not a database.
- Features requiring state (issue assignment, resolve/ignore, thresholds)
  will need explicit, minimal app-side tables when they arrive — deferred
  until those features exist.
