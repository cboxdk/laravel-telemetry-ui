<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the per-viewer tenancy lock — the services and environments the
 * current user is allowed to see — from the resolver an app registers via
 * {@see TelemetryUiManager::restrictScopeUsing()}.
 *
 * A dimension is "locked" only when the resolver explicitly returns that key:
 * `['services' => ['web']]` locks services (and leaves environments open),
 * `['services' => []]` locks services to *nothing* (queries match nothing —
 * fail closed, never open), and a resolver that returns neither key (or is
 * absent) leaves both dimensions unrestricted.
 *
 * The scope switcher only offers the allowed values, and every panel
 * forces every query into them — so a locked viewer can't reach another
 * tenant's data by hand-editing `?service=` or by leaving it blank (which would
 * otherwise query all services). Bound request-scoped, so it never leaks one
 * request's user to the next under Octane.
 */
final class ScopeLock
{
    /** @var array{services: list<string>, environments: list<string>, servicesLocked: bool, environmentsLocked: bool, contexts: array<string, list<string>>|null}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly TelemetryUiManager $manager,
        private readonly Repository $config,
    ) {}

    /**
     * Allowed services, or [] when unrestricted (see {@see servicesLocked()} to
     * disambiguate [] "no lock" from [] "locked to nothing").
     *
     * @return list<string>
     */
    public function services(): array
    {
        return $this->resolve()['services'];
    }

    /**
     * Allowed environments, or [] when unrestricted.
     *
     * @return list<string>
     */
    public function environments(): array
    {
        return $this->resolve()['environments'];
    }

    /**
     * Whether the resolver explicitly constrained services — true even for an
     * empty allowed set (locked to nothing), so callers fail closed.
     */
    public function servicesLocked(): bool
    {
        return $this->resolve()['servicesLocked'];
    }

    public function environmentsLocked(): bool
    {
        return $this->resolve()['environmentsLocked'];
    }

    /**
     * The context dimensions (`Telemetry::context()` keys such as
     * `tenant.id`) this viewer is confined to, with their allowed values —
     * or null when there is no such lock. A resolver returns it as
     * `['contexts' => ['tenant.id' => ['7']]]`.
     *
     * Today it governs monitors (directives) only: queries are scoped by
     * service and environment. A viewer with a context lock may only place
     * monitors on those values, never one on a route, path, job or user,
     * which would record every tenant's traffic.
     *
     * @return array<string, list<string>>|null
     */
    public function contexts(): ?array
    {
        return $this->resolve()['contexts'];
    }

    /**
     * Whether any dimension is locked — the signal to enforce the scope on a
     * raw, hand-supplied query (which never goes through the scoped builders).
     */
    public function restricted(): bool
    {
        $resolved = $this->resolve();

        return $resolved['servicesLocked'] || $resolved['environmentsLocked'];
    }

    /**
     * @return array{services: list<string>, environments: list<string>, servicesLocked: bool, environmentsLocked: bool, contexts: array<string, list<string>>|null}
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // A dynamic per-user hook takes precedence; otherwise fall back to the
        // static config lock. Either way the shape is the same: a key present
        // (even with an empty list) means that dimension is locked.
        $resolver = $this->manager->scopeResolver();
        $raw = $resolver !== null ? (array) $resolver(Auth::user()) : $this->configLock();

        return $this->resolved = [
            'services' => $this->strings($raw['services'] ?? []),
            'environments' => $this->strings($raw['environments'] ?? []),
            'servicesLocked' => array_key_exists('services', $raw),
            'environmentsLocked' => array_key_exists('environments', $raw),
            'contexts' => array_key_exists('contexts', $raw) ? $this->contextLock($raw['contexts']) : null,
        ];
    }

    /**
     * The static config lock (`telemetry-ui.scope.lock`), shaped like a
     * resolver's return: a dimension key is present only when it is locked
     * (a non-null value), so `null`/absent stays open and `[]` locks to nothing.
     *
     * @return array{services?: mixed, environments?: mixed, contexts?: mixed}
     */
    private function configLock(): array
    {
        /** @var array<string, mixed> $lock */
        $lock = (array) $this->config->get('telemetry-ui.scope.lock', []);

        $raw = [];

        foreach (['services', 'environments', 'contexts'] as $dimension) {
            if (($lock[$dimension] ?? null) !== null) {
                $raw[$dimension] = $lock[$dimension];
            }
        }

        return $raw;
    }

    /**
     * `['tenant.id' => ['7'], 'team.id' => 3]` → `['tenant.id' => ['7'], 'team.id' => ['3']]`.
     * Present but malformed (not a keyed array) fails closed: locked to
     * nothing, never "no lock".
     *
     * @return array<string, list<string>>
     */
    private function contextLock(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $lock = [];

        foreach ($raw as $key => $values) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $lock[$key] = array_values(array_map(
                static fn (int|string $v): string => (string) $v,
                array_filter(is_array($values) ? $values : [$values], static fn (mixed $v): bool => is_string($v) || is_int($v)),
            ));
        }

        return $lock;
    }

    /**
     * Whether a whole trace sits inside the lock — every service in it
     * allowed, and with environments locked, every service saying which
     * environment it ran in and that one allowed. A trace id is a deep link
     * anyone can paste (or an agent can pass); this is the check for every
     * read that takes one.
     */
    public function allowsTrace(Trace $trace): bool
    {
        if ($this->servicesLocked()) {
            foreach (array_keys($trace->services) as $service) {
                if (! in_array((string) $service, $this->services(), true)) {
                    return false;
                }
            }
        }

        if ($this->environmentsLocked()) {
            $key = ScopeLabels::traceResourceKey('environment');

            if ($trace->services === []) {
                return false;
            }

            foreach ($trace->services as $attributes) {
                $environment = $attributes[$key] ?? null;

                if (! is_string($environment) || ! in_array($environment, $this->environments(), true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], 'is_string'));
    }
}
