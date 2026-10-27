<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Middleware;

use Cbox\TelemetryUi\Support\MountPath;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route parameters in the mount path (`projects/{project}/telemetry`) are the host's.
 * Laravel hands route parameters to a controller by position, so one left on the route
 * would arrive as the controller's first argument; this takes them off (they stay in
 * `originalParameters()`, which {@see MountPath::base()} reads) and makes them URL
 * defaults, so `route('telemetry-ui.…')` keeps building links inside the same mount.
 *
 * It runs first in the dashboard's group. A host middleware listed before it in
 * `telemetry-ui.middleware` still sees the parameters on the route.
 */
final class BindsMountParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $defaults = [];

            foreach (MountPath::parameters() as $name) {
                $value = $route->originalParameter($name);

                if (is_scalar($value)) {
                    $defaults[$name] = (string) $value;
                }

                $route->forgetParameter($name);
            }

            if ($defaults !== []) {
                URL::defaults($defaults);
            }
        }

        /** @var Response */
        return $next($request);
    }
}
