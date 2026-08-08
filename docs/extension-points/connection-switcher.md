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

A native `<select>` appears in the dashboard header, on every page including
trace detail. Picking an option navigates to that option's `url` — your own
route, doing whatever switching means over there (write a session key, swap a
profile, redirect back into the dashboard).

**Nothing registered means nothing rendered.** A host that never calls
`connection()` sees no foreign chrome, as with
[`navLink()`](navigation.md).

## Marking the current one

`currentConnection()` is what the control shows as selected. If you don't call
it — or you name a value you never registered — the switcher renders a disabled
`Connection…` placeholder instead of quietly selecting the first option. A
control that claims you are on a profile nobody confirmed is worse than one that
admits it doesn't know.

## Why a native select

The scope pickers beside it are searchable comboboxes, which are nicer. This one
is deliberately a plain `<select>`.

The combobox is entirely Alpine-driven: if the JavaScript bundle fails to load,
it renders as a button with no label and no popover. For most controls that is a
degraded experience. For *this* one it means the reader is stranded on whichever
backend they happen to be pointed at, inside a dashboard whose data they may not
be able to explain — with no way out. A native select still opens.

## Escaping

`label` and `url` are ordinary escaped Blade output, and there is no icon or
markup parameter — the same line [`navLink()`](navigation.md) holds. Nothing a
host passes reaches an unescaped sink.

## Managing entries

Registering the same `value` twice replaces the earlier entry, so a host and a
package can both contribute without duplicating one:

```php
TelemetryUi::removeConnection('staging');

TelemetryUi::connections();          // list<ConnectionOption>, in registration order
TelemetryUi::selectedConnection();   // '' when nothing is confirmed current
```
