<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * A link OUT of the dashboard, rendered at the foot of the icon rail.
 *
 * Telemetry UI is normally mounted inside a host application, and a reader who
 * navigates into it can otherwise only leave the way they came. That is fine in
 * a browser tab and a dead end anywhere without one — a desktop shell, a kiosk
 * window, an iframe — where the dashboard becomes the whole application and its
 * own chrome is the only navigation there is. A host registers the way back to
 * its own screens here.
 *
 * The icon is a NAME, not markup. The rail draws SVG, so accepting raw paths
 * would put host-supplied strings into an unescaped sink for no benefit; a name
 * resolved against the list below keeps every byte of that markup ours. Unknown
 * names fall back to the generic link glyph, because a link with a surprising
 * icon is still usable and a link with no icon is invisible in a collapsed rail.
 */
readonly class NavLink
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public ?string $icon = null,
    ) {}

    /**
     * The 24-box SVG body for this link's icon — package-authored markup only.
     */
    public function iconPath(): string
    {
        return match ($this->icon) {
            'back' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'settings' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
            'connection' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
            'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
            'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
            'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            default => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6"/>',
        };
    }
}
