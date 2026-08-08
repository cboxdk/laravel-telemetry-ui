---
title: Links back to your app
description: Add links out of the dashboard for hosts that mount it as the whole UI, where its own chrome is the only navigation there is
weight: 5
---

# Links back to your app

A page registered with `TelemetryUi::page()` lives *inside* the dashboard. A
**nav link** points anywhere you like — your own settings screen, a connection
switcher, your app's home.

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    TelemetryUi::navLink(
        key: 'connections',
        label: 'Connections',
        url: route('connections.index'),
        icon: 'connection',
    );
}
```

The link renders at the foot of the icon rail, above the theme toggle, on every
page including trace detail — so a reader who has drilled three levels into a
trace can still get out in one click.

## When you need this

Mounted in a normal web app, you usually don't: the reader reached the dashboard
through your own navigation and leaves the same way.

It matters when the dashboard **is** the application and there is no surrounding
chrome to return to — a desktop shell, a kiosk window, an iframe, any window
without a visible back button. There, the rail is the only navigation the reader
has, and without a way out the dashboard is a room with no door.

## Icons

`icon` is a **name**, not markup:

`back` · `home` · `settings` · `connection` · `server` · `database` · `user`

Anything else — including `null` — draws a generic external-link glyph, so a
typo costs you the right picture, not the link.

Names rather than SVG is deliberate. The rail draws icons as inline SVG, and
accepting raw path data would put host-supplied strings into an unescaped sink
for no real benefit. Labels and URLs are escaped like any other Blade output.

## Managing links

Registering the same `key` twice replaces the earlier link, so a host and a
package can both call `navLink()` without duplicating an entry:

```php
TelemetryUi::removeNavLink('connections');

TelemetryUi::navLinks();   // list<NavLink>, in registration order
```

Nothing is registered by default — a host that never calls `navLink()` sees no
foreign chrome in its dashboard.
