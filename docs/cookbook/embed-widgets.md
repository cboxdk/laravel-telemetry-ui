---
title: Embed cards as widgets (removed in v2)
description: Embedding cards as Livewire widgets in host pages was removed in v2 — link to the SPA or read the JSON API instead
weight: 4
---

# Embed cards as widgets (removed in v2)

In 1.x every card was a Livewire component, so a host could drop one onto its
own Blade page with `@telemetryUiAssets` and
`<livewire:telemetry-ui.requests-activity service="…" />`.

**v2 removes this.** Livewire is gone, and so are `@telemetryUiAssets`, the
`<livewire:telemetry-ui.*>` components, `<livewire:telemetry-ui.trace-drawer />`
and the `:embedded` prop. There is no drop-in replacement in 2.0.

## What to do instead

**Link to the dashboard.** Every screen has a stable, shareable URL that
carries its scope:

```blade
<a href="{{ url(config('telemetry-ui.path').'/p/requests?service=cbox-web&period=24h') }}">Requests</a>
<a href="{{ url(config('telemetry-ui.path').'/entity/route?value='.rawurlencode('GET /checkout')) }}">Checkout route</a>
<a href="{{ url(config('telemetry-ui.path').'/explore/requests?where[]=user.id='.$user->id) }}">This user's requests</a>
```

**Read the JSON API.** Each panel's data is available at
`GET {path}/api/v2/panels/{id}` with the same scope params
(`service`, `env`, `period`, `from`, `to`), as a typed payload
(`kind: chart | stats | table | …`). Render it however your page renders
things:

```js
const res = await fetch('/telemetry-ui/api/v2/panels/requests-activity?service=cbox-web&period=24h', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
});
const panel = await res.json(); // { id, span, kind: 'chart', series, stats, … }
```

The API is same-origin and session-authenticated, and runs behind the
`viewTelemetryUi` gate (with the per-page check for the page the panel is on)
and the tenancy scope lock, so it can't leak telemetry past your access
control. Don't call it from a public page. See the
[API reference](../core-concepts/api.md) and the payload shapes in
[pages & panels](../core-concepts/pages-and-panels.md#the-payload-contract).

## Reshaping the built-in dashboard

If what you wanted was the dashboard, tailored, use the registry instead
([custom panels](../extension-points/custom-panels.md#add-replace-remove)):
`TelemetryUi::setPanels()`, `removePanel()`, `removePage()`.
