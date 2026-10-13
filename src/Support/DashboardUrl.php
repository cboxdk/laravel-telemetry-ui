<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Illuminate\Support\Facades\Route;

/**
 * Absolute links into the dashboard for text that leaves it — a Markdown
 * snapshot pasted into a ticket or an agent. Built from the named routes, so
 * the configured path and domain apply; null when the dashboard's routes are
 * not registered (the package disabled, or a console process without them).
 */
final class DashboardUrl
{
    /** The SPA page at $path (e.g. `traces/{id}`, `errors/{group}`). */
    public static function page(string $path): ?string
    {
        if (! Route::has('telemetry-ui.spa')) {
            return null;
        }

        return route('telemetry-ui.spa', ['any' => ltrim($path, '/')]);
    }

    /** The trace's own Markdown snapshot on the API. */
    public static function traceMarkdown(string $traceId): ?string
    {
        return Route::has('telemetry-ui.api.trace.markdown')
            ? route('telemetry-ui.api.trace.markdown', ['traceId' => $traceId])
            : null;
    }
}
