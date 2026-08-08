<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * One entry in the header's connection switcher — a backend profile the host
 * can send the reader to.
 *
 * A host that mounts the dashboard as its whole UI usually owns the notion of
 * "which backend am I looking at" (see
 * {@see TelemetryUiManager::resolveConnectionsUsing()}). Before this existed,
 * switching meant leaving the dashboard for the host's own screen and coming
 * back — three navigations for a one-word decision. The host registers its
 * profiles here instead and the dashboard header draws a native `<select>`
 * that goes straight there.
 *
 * The URL is where picking this option navigates to; it is the host's own
 * route, and the host decides what switching means (a session write, a
 * redirect back, a profile change). Both label and URL are escaped as ordinary
 * Blade output — the same line {@see NavLink} holds: nothing a host passes ever
 * reaches an unescaped sink.
 */
readonly class ConnectionOption
{
    public function __construct(
        public string $value,
        public string $label,
        public string $url,
    ) {}
}
