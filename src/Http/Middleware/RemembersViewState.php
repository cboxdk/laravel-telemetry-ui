<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Middleware;

use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Support\ViewState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the resolved {@see ViewState} back to the browser after a page render,
 * so the next request — a reload, a host link that knows nothing about the
 * dashboard's query string, a trace drill-in — starts from the same window.
 *
 * Only page renders persist. A Livewire update reads the state (off the
 * Referer) but must never write it: those requests are driven by cards, and a
 * card has no business deciding what the reader's window is.
 */
final class RemembersViewState
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $state = app(ViewState::class);

        if (! $state->enabled() || ! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            return $response;
        }

        // Nothing moved: no Set-Cookie on an otherwise identical page, and no
        // event for a host that only wants to hear about real changes.
        if (! $state->changed()) {
            return $response;
        }

        // Set on the response rather than queued: Cookie::queue() needs the
        // web group's AddQueuedCookiesToResponse, and telemetry-ui.middleware
        // is the host's to configure.
        $response->headers->setCookie($state->cookie());

        event(new ViewStateChanged($state, Auth::user()));

        return $response;
    }
}
