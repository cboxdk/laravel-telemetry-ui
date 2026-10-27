<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Http\Middleware\BindsMountParameters;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Where the dashboard is mounted (`telemetry-ui.path`), which may name route parameters,
 * e.g. `projects/{project}/telemetry`: a host that keeps the tenant in the URL gets one
 * dashboard per tenant, and two tabs on two tenants never share state.
 *
 * The parameters belong to the host, not to the dashboard's own routes:
 * {@see BindsMountParameters} takes them off the
 * route before a controller sees them and makes them URL defaults, so every link the
 * dashboard builds stays inside the same mount.
 */
final class MountPath
{
    public static function configured(): string
    {
        return trim((string) config('telemetry-ui.path', 'telemetry-ui'), '/');
    }

    /**
     * The route parameters the mount path names, in order.
     *
     * @return list<string>
     */
    public static function parameters(): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/', self::configured(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The path this request is mounted at, parameters filled in from the route: what the
     * browser should treat as the dashboard's root. '' when mounted at the site root.
     */
    public static function base(Request $request): string
    {
        $path = self::configured();
        $route = $request->route();

        if ($route instanceof Route) {
            foreach (self::parameters() as $name) {
                $value = $route->originalParameter($name);
                $path = (string) preg_replace('/\{'.preg_quote($name, '/').'\??\}/', rawurlencode(is_scalar($value) ? (string) $value : ''), $path);
            }
        }

        return $path === '' ? '' : '/'.$path;
    }

    /** The mount path as a wildcard pattern, every parameter matching anything (`projects/*\/telemetry`). */
    public static function pattern(): string
    {
        return (string) preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\??\}/', '*', self::configured());
    }
}
