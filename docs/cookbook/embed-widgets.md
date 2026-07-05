---
title: Embed cards as widgets
description: Drop individual telemetry cards onto your own pages, not just the full dashboard
weight: 4
---

# Embed cards as widgets

Every card is a Livewire component, so you don't have to send people to the
full dashboard — you can drop one onto any page in your app (a Statamic CP
screen, a customer dashboard, an admin panel).

## 1. Load the assets once

The cards need the dashboard's CSS + ECharts bundle. Add this to your page's
`<head>` (Livewire and Alpine are your app's own — include them as usual):

```blade
<head>
    …
    @telemetryUiAssets
</head>
```

## 2. Drop in a card

Reference a card by its component name — `telemetry-ui.` + the kebab-cased class
basename — and pass the scope as props:

```blade
<livewire:telemetry-ui.requests-activity service="cbox-web" period="24h" />
<livewire:telemetry-ui.request-duration service="cbox-web" environment="production" />
<livewire:telemetry-ui.analytics-overview service="cbox-web" period="7d" />
```

Passed scope (`service`, `environment`, `period`, `from`, `to`) wins over the
URL, so the widget is self-contained. Any built-in or custom card works.

For the slide-in trace drawer (opened from row clicks), also include it once:

```blade
<livewire:telemetry-ui.trace-drawer />
```

Without it, trace links fall back to the full trace page.

## Security

An embedded card still enforces the **`viewTelemetryUi` gate** at mount — a
widget dropped on a page can't leak telemetry past your access control (define
the gate as usual; see [authorization](../core-concepts/authorization.md)). The
**tenancy scope lock** (`restrictScopeUsing`) applies too, so an embedded widget
is constrained to the viewer's allowed services/environments. Beyond that, the
host page's own auth is the outer boundary — don't embed a card on a public
page.

## Reshaping the built-in dashboard

If you want the pre-built dashboard but tailored, you don't have to embed
piecemeal — the registry can add, replace and remove
([custom cards](../extension-points/custom-cards.md#add-replace-remove)):
`TelemetryUi::setCards()`, `removeCard()`, `removePage()`.
