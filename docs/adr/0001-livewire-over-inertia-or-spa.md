---
title: "ADR 0001: Livewire over Inertia or a prebuilt SPA"
description: Server-driven cards so third-party packages can extend the dashboard without a JS build
weight: 1
---

# ADR 0001: Livewire over Inertia or a prebuilt SPA

**Status:** accepted (2026-07-03)

## Context

The dashboard must be extensible by other Composer packages (queue autoscale,
queue metrics, internal systems) and will grow interactive workflows: ticket
creation, and later AI resolve/chat. Three stacks were considered: a
prebuilt SPA (Horizon model), Inertia, and Livewire (Pulse model).

## Decision

Livewire 3. Cards are PHP classes + Blade views; the only JavaScript is one
pre-built bundle shipped by this package (ECharts + Alpine glue), reused by
extenders through Blade components.

## Consequences

- Extension packages contribute panels with zero JS tooling — a class-string
  registration and a Blade view.
- Interactivity (forms, actions, `wire:stream` for AI output) is first-class.
- A prebuilt SPA would make every third-party panel a compiled-JS problem;
  Inertia additionally couples to the host app's Inertia setup. Both rejected.
- Cost: a runtime dependency on `livewire/livewire ^3.6` in host apps, and
  chart interactivity is bounded by what the shipped bundle exposes.
- Boot hygiene is a hard rule: the service provider registers maps only
  (routes, bindings, class-strings); connectors and Livewire components
  resolve on first dashboard request.
