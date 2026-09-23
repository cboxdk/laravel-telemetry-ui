<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Dimensions;

/**
 * The dimension registry: the built-in dimensions laravel-telemetry emits plus
 * whatever the host declares. Data-only — registering costs nothing at boot.
 *
 * A host re-declaring a built-in key replaces it (e.g. to add a link out on
 * `user.id`).
 */
final class Dimensions
{
    /** @var array<string, Dimension> */
    private array $dimensions;

    public function __construct()
    {
        $this->dimensions = [];

        foreach (self::builtins() as $dimension) {
            $this->dimensions[$dimension->key] = $dimension;
        }
    }

    public function add(Dimension $dimension): void
    {
        $this->dimensions[$dimension->key] = $dimension;
    }

    public function remove(string $key): void
    {
        unset($this->dimensions[$key]);
    }

    /**
     * @return list<Dimension>
     */
    public function all(): array
    {
        return array_values($this->dimensions);
    }

    /**
     * Attributes plus every derived dimension that can be read out of them —
     * so a chip, facet or group-by sees `hubhus.screen` on a span that only
     * carries `http.route`.
     *
     * @param  array<string, string>  $attributes
     * @return array<string, string>
     */
    public function derive(array $attributes): array
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->derived === null || isset($attributes[$dimension->key])) {
                continue;
            }

            $value = $dimension->valueIn($attributes);

            if ($value !== null) {
                $attributes[$dimension->key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Derived dimensions, by key — the read-side keys a backend can't aggregate.
     *
     * @return array<string, Dimension>
     */
    public function derived(): array
    {
        return array_filter($this->dimensions, static fn (Dimension $d): bool => $d->derived !== null);
    }

    public function get(string $key): ?Dimension
    {
        return $this->dimensions[$key] ?? null;
    }

    /**
     * The dimension for an entity slug (`route`) or raw key (`hubhus.customer_id`).
     */
    public function forEntity(string $slug): ?Dimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->entitySlug() === $slug) {
                return $dimension;
            }
        }

        return $this->dimensions[$slug] ?? null;
    }

    /**
     * The dimension for a filter key, or an ad-hoc span-scoped one for an
     * undeclared attribute (every attribute stays filterable by raw key).
     */
    public function resolve(string $key): Dimension
    {
        return $this->dimensions[$key] ?? new Dimension($key, $key, scope: self::guessScope($key));
    }

    /**
     * Links out to the host app for the declared dimensions present in an
     * attribute bag — `{key: {value: url}}`, only for keys that link out.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function linksFor(array $attributes): array
    {
        $links = [];

        foreach ($this->dimensions as $key => $dimension) {
            if ($dimension->link === null || ! isset($attributes[$key]) || ! is_scalar($attributes[$key])) {
                continue;
            }

            $url = $dimension->linkFor((string) $attributes[$key]);

            if ($url !== null) {
                $links[$key] = $url;
            }
        }

        return $links;
    }

    /**
     * Resource attributes laravel-telemetry stamps on every span; anything else
     * is a span attribute unless declared otherwise.
     */
    private static function guessScope(string $key): string
    {
        return match (true) {
            in_array($key, ['status', 'name', 'duration', 'kind', 'statusMessage'], true) => 'intrinsic',
            str_starts_with($key, 'service.'),
            str_starts_with($key, 'deployment.'),
            str_starts_with($key, 'host.'),
            str_starts_with($key, 'process.'),
            str_starts_with($key, 'telemetry.sdk.'),
            str_starts_with($key, 'os.'),
            str_starts_with($key, 'container.'),
            str_starts_with($key, 'k8s.'),
            str_starts_with($key, 'cloud.') => 'resource',
            default => 'span',
        };
    }

    /**
     * The attributes laravel-telemetry v2 emits that are worth promoting.
     *
     * @return list<Dimension>
     */
    public static function builtins(): array
    {
        $r = ['requests', 'traces'];

        return [
            new Dimension('status', 'Span status', 'Request', scope: 'intrinsic', builtin: true, signals: $r, plural: 'Statuses'),
            new Dimension('http.response.status_code', 'Status code', 'Request', builtin: true, signals: $r, format: 'status'),
            new Dimension('http.request.method', 'Method', 'Request', builtin: true, signals: $r),
            new Dimension('http.route', 'Route', 'Request', entity: 'route', builtin: true, signals: $r),
            new Dimension('url.path', 'Path', 'Request', entity: 'path', builtin: true, signals: ['traces']),
            new Dimension('user.id', 'User', 'Identity', entity: 'user', builtin: true, signals: $r),
            new Dimension('client.address', 'Client IP', 'Identity', entity: 'ip', builtin: true, signals: $r),
            new Dimension('geo.country.iso_code', 'Country', 'Identity', builtin: true, signals: ['requests'], plural: 'Countries'),
            new Dimension('device.type', 'Device', 'Identity', builtin: true, signals: ['requests']),
            new Dimension('service.name', 'Service', 'Infrastructure', entity: 'service', scope: 'resource', builtin: true, signals: ['traces']),
            new Dimension('deployment.environment.name', 'Environment', 'Infrastructure', scope: 'resource', builtin: true, signals: []),
            new Dimension('host.name', 'Host', 'Infrastructure', entity: 'host', scope: 'resource', builtin: true, signals: $r),
            new Dimension('deployment.id', 'Deploy', 'Infrastructure', scope: 'resource', builtin: true, signals: []),
            new Dimension('db.query.text', 'Query', 'Database', entity: 'query', builtin: true, signals: [], plural: 'Queries', format: 'sql'),
            new Dimension('db.system.name', 'DB system', 'Database', builtin: true, signals: []),
            new Dimension('view.name', 'View', 'Rendering', entity: 'view', builtin: true, signals: []),
            new Dimension('laravel.job.class', 'Job', 'Queue', entity: 'job', builtin: true, signals: []),
            new Dimension('messaging.destination.name', 'Queue', 'Queue', entity: 'queue', builtin: true, signals: []),
            new Dimension('server.address', 'Outgoing host', 'Outgoing', entity: 'outgoing', builtin: true, signals: []),
            new Dimension('laravel.command', 'Command', 'Console', entity: 'command', builtin: true, signals: []),
            new Dimension('name', 'Span name', 'Span', scope: 'intrinsic', builtin: true, signals: ['traces']),
        ];
    }
}
