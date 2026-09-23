---
title: Embed the dashboard in your own app
description: Mount panels, Explore and entity pages as React components inside a host app — installed from vendor/, no npm registry
weight: 4
---

# Embed the dashboard in your own app

The dashboard is a React app the package serves itself, but it is also a set of
components you can mount inside your own. A host running React (Inertia, a
plain SPA, anything with a bundler) can put a panel, the Explore surface or a
whole page inside its own chrome, with its own navigation around it.

There is no npm registry involved: the components ship **inside the composer
package**, and npm installs them from `vendor/`.

## Install

```bash
composer require cboxdk/laravel-telemetry-ui
```

```jsonc
// package.json
{
    "dependencies": {
        "@cboxdk/telemetry-ui": "file:vendor/cboxdk/laravel-telemetry-ui/resources/app"
    }
}
```

```bash
npm install
```

npm reads the package's own manifest and installs what it needs (TanStack
Query/Router/Virtual, ECharts). React is a peer dependency — yours, so there is
only ever one copy.

## Mount a panel

```jsx
import { TelemetryUiProvider, TelemetryPanel } from '@cboxdk/telemetry-ui';
import '@cboxdk/telemetry-ui/styles.css';

export default function Overview({ csrf }) {
    return (
        <TelemetryUiProvider config={{ base: '/observability', csrf }}>
            <TelemetryPanel id="requests-activity" />
            <TelemetryPanel id="routes-table" />
        </TelemetryUiProvider>
    );
}
```

`base` is wherever you mounted the package (`telemetry-ui.path`). The provider
fetches `/bootstrap` once and shares it, so several components on a page cost
one bootstrap between them.

In an Inertia app, `csrf` is the token you already share with the front end:

```php
// app/Http/Middleware/HandleInertiaRequests.php
'csrf' => csrf_token(),
```

It is only needed for the writes (remembering the time window, filing an
issue); read-only embeds work without it.

## What you can mount

| Component | What it renders |
| --- | --- |
| `<TelemetryPanel id params />` | One panel, by the id the API knows it by |
| `<TelemetryPage page params />` | A whole registered page of panels |
| `<TelemetryExplore signal onSignalChange />` | The Explore surface over requests / traces / logs / errors; its signal tabs switch in place |
| `<TelemetryEntity type />` | One entity's story (`?value=` in the view state) |
| `<TelemetryEntities type />` | Every value of an entity type, with RED |
| `<TelemetryTrace traceId />` | One trace: story, waterfall, logs, context |
| `<TelemetryIssue group />` | One error group |
| `<TelemetryToolbar />` | The scope and window controls — service, environment, time window, refresh, copy link — for the top of your page; everything mounted beside it follows them |

Hooks and types come out of the same entry (`usePanel`, `useExplore`,
`useEntityStory`, `useTrace`, `apiUrl`, and the payload types) for when you
want the data and none of our markup.

## Where the view state lives

The dashboard's rule is that the URL is the query: filters, the time window and
the drawer stack are search params. Embedded, that URL is the host's, so by
default the state lives in React instead — filtering a panel inside your page
does not rewrite your address bar.

To put it in your URL, control it:

```jsx
const [search, setSearch] = useState('?period=24h');

<TelemetryUiProvider config={{ base: '/observability' }} search={search} onSearchChange={setSearch}>
    <TelemetryExplore signal="requests" />
</TelemetryUiProvider>
```

Now `search` is a plain query string you can push into your own router
(`router.visit(url, { preserveState: true })` in Inertia), and a deep link into
your page restores the exact view.

## Links to other pages

Anything that stays on the mounted view happens in place: filters, group-by,
the time window, and the drawer — clicking a request or an error opens its
trace or issue in the same slide-over the dashboard uses, inside your page.

A link to a *different* page (a route's entity page from a panel row, "Full
page" in the drawer) can't render where you mounted one component, so by
default it opens in the full dashboard at `{base}`. Handle it yourself to keep
people in your app — for example on a page of yours that embeds the target:

```jsx
<TelemetryUiProvider
    config={{ base: '/observability' }}
    onNavigate={({ pathname, search, url }) => {
        // pathname is the dashboard path: /entity/route, /traces/{id}, /p/jobs …
        if (pathname.startsWith('/traces/')) router.visit(`/ops${pathname}${search}`);
        else window.location.assign(url);
    }}
>
```

Real anchors carry the dashboard URL as their `href`, so middle-click and
"copy link" always land somewhere that renders the target. If you serve the
pages yourself, map the anchors too, so a copied link opens your page:

```jsx
<TelemetryUiProvider
    config={{ base: '/observability' }}
    onNavigate={({ pathname, search }) => router.visit(`/ops${pathname}${search}`)}
    href={({ pathname, search }) => `/ops${pathname}${search}`}
>
``` Embedded
components leave the document title and the page's theme class alone; those
are yours.

## Styling

`@cboxdk/telemetry-ui/styles.css` carries the design tokens and the components,
and nothing that touches your `<body>` — the element rules are scoped to the
provider's own root. The tokens themselves are declared on `:root`, though,
under common names (`--primary`, `--border`, `--accent`, `--font-sans`,
`--radius-sm`, …), so in an app whose own design system uses the same names
they override yours everywhere. Import `@cboxdk/telemetry-ui/components.css`
instead — the component rules alone — and answer the tokens on `.t-scope`
from your own palette (see the override example below; every token in
`tokens.css` is read by some component). Two knobs either way:

- **Dark mode**: the components follow a `dark` class on an ancestor. Pass
  `className="dark"` to the provider to force it, or let your own theme class
  on `<html>` decide.
- **Fonts**: the dashboard's own faces are a separate, optional import
  (`@cboxdk/telemetry-ui/fonts.css`). Without it the components inherit your
  font stack.

Override the tokens to match your product:

```css
.t-scope {
    --primary: oklch(0.55 0.18 250);
    --radius: 10px;
}
```

## Authorization

Every component talks to the same API the standalone dashboard uses: same
`viewTelemetryUi` gate (including the per-page check), same tenancy scope lock,
same typed errors. Embedding grants no access your gate doesn't already allow —
but it does mean a page that renders these components is a page that shows
telemetry, so gate the page itself accordingly.

## When to link instead

For the whole dashboard — rail, sub-navigation, every page — mount the package
at a path and link to it. It carries its own routing, and the shell is built to
own the page:

```blade
<a href="{{ url(config('telemetry-ui.path').'/explore/requests?where[]=user.id='.$user->id) }}">This user's requests</a>
```

`TelemetryUi::navLink()` puts a link back to your app in the rail, and
`telemetry-ui.brand` gives it your name and logo, so the jump doesn't feel like
leaving.

## What 1.x had

In 1.x every card was a Livewire component, dropped onto a Blade page with
`@telemetryUiAssets` and `<livewire:telemetry-ui.requests-activity />`. That is
gone: v2 has no Livewire, no `@telemetryUiAssets` and no Blade components. The
React components above are the replacement; for a Blade-only host, read the
[JSON API](../core-concepts/api.md) and render it however that page renders
things.
