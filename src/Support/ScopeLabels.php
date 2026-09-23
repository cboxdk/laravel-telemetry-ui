<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;

/**
 * The names the scope dimensions (service, environment, host) carry in each
 * backend — the one place they are spelled.
 *
 * The defaults are what cboxdk/laravel-telemetry emits through an OTLP
 * pipeline: `service_name` / `deployment_environment_name` / `host_name` as
 * Prometheus and Loki labels, `resource.service.name` /
 * `resource.deployment.environment.name` / `resource.host.name` in TraceQL.
 * A fleet whose telemetry comes from elsewhere spells them differently — an
 * Alloy/Prometheus scrape that stamps `environment` and `hostname` as external
 * labels, or an eBPF agent that still sends the pre-1.27 semantic convention
 * `deployment.environment` — and without this every scoped query would match
 * nothing. Configured under `telemetry-ui.scope.labels.<signal>.<dimension>`.
 */
final class ScopeLabels
{
    public const SIGNALS = ['metrics', 'traces', 'logs'];

    public const DIMENSIONS = ['service', 'environment', 'host'];

    private const DEFAULTS = [
        'metrics' => ['service' => 'service_name', 'environment' => 'deployment_environment_name', 'host' => 'host_name'],
        'traces' => ['service' => 'resource.service.name', 'environment' => 'resource.deployment.environment.name', 'host' => 'resource.host.name'],
        'logs' => ['service' => 'service_name', 'environment' => 'deployment_environment_name', 'host' => 'host_name'],
    ];

    /** A Prometheus/Mimir label name, e.g. `metrics('environment')`. */
    public static function metrics(string $dimension): string
    {
        return self::name('metrics', $dimension);
    }

    /** A TraceQL attribute with its scope prefix, e.g. `resource.service.name`. */
    public static function traces(string $dimension): string
    {
        return self::name('traces', $dimension);
    }

    /**
     * The same trace attribute as a key of a trace's resource attributes (the
     * TraceQL scope prefix removed): `resource.deployment.environment.name` →
     * `deployment.environment.name`.
     */
    public static function traceResourceKey(string $dimension): string
    {
        $name = self::traces($dimension);

        return str_starts_with($name, 'resource.') ? substr($name, strlen('resource.')) : ltrim($name, '.');
    }

    /** A Loki label name. */
    public static function logs(string $dimension): string
    {
        return self::name('logs', $dimension);
    }

    /**
     * A log stream selector for a set of services: exact for one, an RE2
     * alternation for several, and only as a last resort "any service" (a
     * trace whose spans carry no service name). Scoping a trace's log lookups
     * to the trace's own services is the difference between reading a handful
     * of streams and scanning every stream in the store.
     *
     * @param  list<string>  $services
     */
    public static function logServiceMatcher(array $services): LabelMatcher
    {
        $services = array_values(array_filter($services, static fn (string $s): bool => $s !== ''));

        return match (count($services)) {
            0 => new LabelMatcher(self::logs('service'), MatchOp::Re, '.+'),
            1 => new LabelMatcher(self::logs('service'), MatchOp::Eq, $services[0]),
            default => new LabelMatcher(self::logs('service'), MatchOp::Re, implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $services))),
        };
    }

    private static function name(string $signal, string $dimension): string
    {
        $configured = config("telemetry-ui.scope.labels.$signal.$dimension");

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::DEFAULTS[$signal][$dimension] ?? throw new \InvalidArgumentException("Unknown scope label [$signal.$dimension].");
    }
}
