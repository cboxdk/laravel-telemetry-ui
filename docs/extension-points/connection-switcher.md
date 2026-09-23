---
title: Connection switcher
description: Offer the host's backend profiles as a select in the dashboard header, so switching doesn't mean leaving
weight: 7
---

# Connection switcher

[`resolveConnectionsUsing()`](../core-concepts/connections.md) decides which
backend the dashboard reads from. The **connection switcher** lets the reader
change it without leaving the dashboard for one of your own screens and coming
back.

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    foreach ($this->profiles() as $profile) {
        TelemetryUi::connection(
            value: $profile->id,
            label: $profile->name,
            url: route('profiles.activate', $profile),
        );
    }

    TelemetryUi::currentConnection($this->activeProfileId());
}
```

A **Backend** picker appears in the dashboard's top bar, on every screen
including trace detail. Picking an option does a full navigation to that
option's `url` — your own route, doing whatever switching means over there
(write a session key, swap a profile, redirect back into the dashboard). The
SPA gets the list from `GET /api/v2/bootstrap` (`connections`,
`currentConnection`).

**Nothing registered means nothing rendered.** A host that never calls
`connection()` sees no foreign chrome, as with
[`navLink()`](navigation.md).

## Marking the current one

`currentConnection()` is what the control shows as selected. If you don't call
it — or you name a value you never registered — the switcher shows a
`Connection` placeholder instead of quietly selecting the first option. A
control that claims you are on a profile nobody confirmed is worse than one that
admits it doesn't know.

In 1.x this was a native `<select>` so it still worked if the Alpine bundle
failed to load. In v2 the whole dashboard is the SPA, so it uses the same
combobox as the other pickers; if the app doesn't load, nothing does.

## Escaping

`label` is rendered as text, and there is no icon or markup parameter — the
same line [`navLink()`](navigation.md) holds. `url` is used as a navigation
target as given, so only pass URLs you build yourself.

## Managing entries

Registering the same `value` twice replaces the earlier entry, so a host and a
package can both contribute without duplicating one:

```php
TelemetryUi::removeConnection('staging');

TelemetryUi::connections();          // list<ConnectionOption>, in registration order
TelemetryUi::selectedConnection();   // '' when nothing is confirmed current
```
