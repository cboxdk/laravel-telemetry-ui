---
title: View state
description: The reader's time window, auto-refresh interval and scope — remembered across navigation, readable and settable from the host
weight: 6
---

# View state

The **view state** is the three things that decide what a reader is looking at:

- the **time window** — a preset period (`1h`, `7d`, …) or a custom absolute range
- the **auto-refresh interval** — seconds, or off
- the **scope** — service and environment

It is resolved once per request and remembered between them, so it survives a
reload, a link that carries no query string, and a drill into a trace and back.

## Precedence

An **explicit URL parameter always wins**, and taking one updates what is
remembered:

| The request says | The reader gets |
| --- | --- |
| `?period=7d` | 7 days — and 7 days is remembered from now on |
| nothing | whatever they last chose |
| nothing, and they never chose | the default (`1h`, all scopes, refresh off) |

That ordering is what keeps deep links honest. A link someone shares shows the
**sender's** view; a bare link shows the **recipient's** saved one. "Copy link"
pins the whole state into the URL for exactly this reason — copying the address
bar verbatim would hand over a link that silently retargets to whatever range
the recipient last used.

The time window is resolved as **one unit**: `period`, `from` and `to` only mean
anything together. A request carrying any of them defines the whole window;
`from`/`to` never fall back to a remembered value key-by-key. (Otherwise the
preset buttons — which send `?period=` and deliberately drop `from`/`to` —
would appear to do nothing while a remembered zoom was active.)

## Reading it

```php
use Cbox\TelemetryUi\Facades\TelemetryUi;

$state = TelemetryUi::viewState();

$state->period();          // Period enum — Period::SevenDays
$state->from();            // '1735686000' | 'now-2h' | '' when no custom range
$state->to();
$state->hasCustomRange();  // bool
$state->range();           // [DateTimeImmutable $start, DateTimeImmutable $end]
$state->refresh();         // int seconds; 0 = off
$state->service();         // '' = all services
$state->environment();
$state->queryParams();     // ['period' => '7d', 'service' => '', 'env' => '']
```

`range()` is the one to reach for when you want the actual window: it applies
the custom range when there is one and the preset period otherwise, the same
rule every panel uses.

## Setting it

```php
TelemetryUi::viewState()->put(['period' => '24h']);
TelemetryUi::viewState()->put(['from' => 'now-3h', 'to' => 'now']);
TelemetryUi::viewState()->put(['service' => 'checkout', 'refresh' => 30]);

TelemetryUi::viewState()->forget();   // back to defaults
```

Only the keys you pass change, each validated exactly as a URL parameter is —
an unknown period falls back to the default, a half-open range is dropped, an
interval outside the offered set reads as off. The new state is written back on
that response, so redirecting into the dashboard afterwards lands on it.

## Being told when it changes

```php
use Cbox\TelemetryUi\Events\ViewStateChanged;

Event::listen(function (ViewStateChanged $event): void {
    [$start, $end] = $event->state->range();

    $this->shell->setTitle('Telemetry — '.$event->state->period()->label());
});
```

Fired once per request, and only when the resolved state differs from what the
request arrived with — a reader paging around inside one window is quiet.

## How the SPA uses it

The SPA keeps the window and scope in its own URL. When the reader moves them
(a preset, a brushed range, the scope picker, the refresh interval), it reports
the new values with `POST /api/v2/view-state`, which updates the cookie and
fires `ViewStateChanged` if anything actually changed. The bootstrap endpoint
hands the remembered state back (`state`) so a bare URL opens on it.

Only the SPA shell render and that `POST` ever write the cookie. API reads —
every panel, Explore and facet request — carry the scope in their own query
string and fall back to the remembered state for anything they leave out, but
never write it: a page fetching ten panels would otherwise set ten cookies and
fire ten events.

## Why a cookie

The server has to know the window before a request runs its queries, including
the very first request of a visit and a host link that carries no query string.
A cookie is already on the request. It also needs no session, no database and no
authenticated user — which matters for a host that has none, such as a desktop
shell bound to one connection profile.

The cookie is a **preference, never an authorization input**. It grants
nothing: a
remembered scope is bounded by the tenancy lock on the way in, and every query is
independently forced into that lock downstream — exactly as it is for a
hand-typed `?service=`. A remembered service that a
[`restrictScopeUsing()`](../core-concepts/authorization.md) lock does not allow
is dropped rather than obeyed, so the scope picker and the queries agree instead
of one quietly showing something the other refuses.

## The auto-refresh interval

The interval has **no URL form**, deliberately. It is a property of the reader's
own session, not of the view being shared: a "Copy link" that made someone
else's dashboard start polling would be a surprise, not a feature. It is
remembered like everything else, and the offered intervals are
`ViewState::INTERVALS` (0, 10, 30, 60 seconds).

## Configuration

```php
'state' => [
    'enabled'  => true,                 // false = URL-or-default, nothing remembered
    'cookie'   => 'telemetry_ui_view',
    'lifetime' => 60 * 24 * 365,        // minutes
],
```

Turning `enabled` off restores the pre-cookie behaviour exactly: the URL decides,
or the default does, and nothing is carried between requests.
