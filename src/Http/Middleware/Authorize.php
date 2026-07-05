<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pass the page slug so the gate can restrict individual pages. Null on
        // Livewire updates (this middleware is persistent) and any non-page
        // route, where only the master check applies.
        abort_unless(Gate::allows('viewTelemetryUi', [$this->page($request)]), 403);

        return $next($request);
    }

    private function page(Request $request): ?string
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($name === 'telemetry-ui.trace') {
            return 'traces';
        }

        if ($name === 'telemetry-ui.page') {
            $page = $route?->parameter('page');

            return is_string($page) && $page !== '' ? $page : 'dashboard';
        }

        return null;
    }
}
