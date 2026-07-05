<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\TelemetryUiManager;
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
 * The scope switcher only offers the allowed values, and {@see Card}
 * forces every query into them — so a locked viewer can't reach another
 * tenant's data by hand-editing `?service=` or by leaving it blank (which would
 * otherwise query all services). Bound request-scoped, so it never leaks one
 * request's user to the next under Octane.
 */
final class ScopeLock
{
    /** @var array{services: list<string>, environments: list<string>, servicesLocked: bool, environmentsLocked: bool}|null */
    private ?array $resolved = null;

    public function __construct(private readonly TelemetryUiManager $manager) {}

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
     * Whether any dimension is locked — the signal to enforce the scope on a
     * raw, hand-supplied query (which never goes through the scoped builders).
     */
    public function restricted(): bool
    {
        $resolved = $this->resolve();

        return $resolved['servicesLocked'] || $resolved['environmentsLocked'];
    }

    /**
     * @return array{services: list<string>, environments: list<string>, servicesLocked: bool, environmentsLocked: bool}
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolver = $this->manager->scopeResolver();
        $raw = $resolver !== null ? (array) $resolver(Auth::user()) : [];

        return $this->resolved = [
            'services' => $this->strings($raw['services'] ?? []),
            'environments' => $this->strings($raw['environments'] ?? []),
            'servicesLocked' => array_key_exists('services', $raw),
            'environmentsLocked' => array_key_exists('environments', $raw),
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], 'is_string'));
    }
}
