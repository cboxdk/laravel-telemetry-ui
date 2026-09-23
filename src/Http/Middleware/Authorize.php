<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Middleware;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `viewTelemetryUi` master check on every dashboard route. Per-page checks
 * (the gate's second argument) happen where a page is known: the page and
 * panel endpoints, and the navigation the bootstrap endpoint returns.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::allows('viewTelemetryUi', [null])) {
            return $next($request);
        }

        if ($request->route()?->getName() !== 'telemetry-ui.spa' || $request->expectsJson()) {
            return ApiError::forbidden('You are not allowed to view the telemetry dashboard.');
        }

        abort(403);
    }
}
