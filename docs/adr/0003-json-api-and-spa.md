---
title: "ADR 0003: Versioned JSON API + prebuilt React SPA"
description: Remove Livewire; the package serves a JSON API and a compiled single-page app, and extenders write PHP panels that return typed payloads
weight: 3
---

# ADR 0003: Versioned JSON API + prebuilt React SPA

**Status:** accepted (2026-09) — supersedes
[ADR 0001](0001-livewire-over-inertia-or-spa.md)

## Context

ADR 0001 chose Livewire so third-party packages could add cards with a PHP
class and a Blade view, and rejected a prebuilt SPA because every third-party
panel would become a compiled-JS problem.

In use, the Livewire model held the product back:

- **Interaction.** Brushing a time range across charts, virtualised tables over
  thousands of rows, live tail and a stacked drawer are client-side concerns.
  Livewire round-tripped the server for state that never needed to leave the
  browser, and `wire:navigate` added transitions and a progress bar to every
  click.
- **Coupling.** View, state and query lived in one PHP class, with no seam for
  a second consumer short of the MCP server.
- **Delivery.** One un-split ~1 MB ECharts bundle, with no code splitting or
  hashed chunks.
- **Product.** Fixed cards on fixed pages could not deliver the drill-down the
  product needs: filtering and grouping by any attribute, and entity pages that
  explain a route or a customer rather than dumping its attributes.

The concern in ADR 0001 — extenders should not need a JS build — still holds.

## Decision

A big-bang v2 that removes Livewire entirely:

- The package serves a **versioned JSON API** under `{path}/api/v2` and a
  **prebuilt React SPA** (React, Vite, TypeScript, TanStack Query/Router/
  Virtual, ECharts) from `public/build`. The SPA talks to the API only over
  plain `fetch`; Inertia is not used, because it couples routing to the host app
  and is awkward to ship inside a mountable package.
- It stays **one Composer package**. The built SPA is committed, so hosts need
  no Node toolchain.
- Extenders keep a PHP-only path: a **panel** is a framework-free PHP class
  whose `data()` returns a typed payload (`Panels\Ui`), and the SPA has a
  renderer for every payload kind. A third-party panel is data, not compiled
  JS — which is what made ADR 0001 reject a prebuilt SPA.
- Auth is the host session: same-origin, CSRF on writes, the `viewTelemetryUi`
  gate on every route. Scope and tenancy move from component state into a
  per-request `RequestScope` with the same fail-closed semantics.
- The query core (contracts, drivers, query IR, `Analysis/`, MCP) is kept
  unchanged.

## Consequences

- No `livewire/livewire` dependency in host apps.
- Extension packages contribute panels with zero JS tooling, but only in the
  payload kinds the SPA renders (chart, stats, table, bars, composite, heatmap,
  graph, logs, header, kv, code, callout). A genuinely new visualisation needs a
  change to this package.
- Embedding cards as Livewire widgets in host Blade pages is gone, with no
  replacement yet; hosts link to the SPA or read the API.
- Livewire-style panel interactivity (`wire:click` actions, `wire:stream`) is
  replaced by panel params and controls (`#[Param]`, `Ui::select()`), dedicated
  endpoints (issues) and SSE.
- The API is a contract: shapes in `resources/app/src/api/types.ts` mirror
  `Panels\Ui`, and changes within `v2` are additive.
- Every UI change needs `npm run build` and a commit of `public/build`.
- Boot hygiene is unchanged: the service provider registers maps only;
  connectors resolve on first request.
