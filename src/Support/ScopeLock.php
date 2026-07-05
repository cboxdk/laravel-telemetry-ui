<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the per-viewer tenancy lock — the services and environments the
 * current user is allowed to see — from the resolver an app registers via
 * {@see TelemetryUiManager::restrictScopeUsing()}. An empty allowed set means
 * "unrestricted" for that dimension.
 *
 * The scope switcher only offers the allowed values, and {@see Card}
 * forces every query into them — so a locked viewer can't reach another
 * tenant's data by hand-editing `?service=` or by leaving it blank (which would
 * otherwise query all services). Bound request-scoped, so it never leaks one
 * request's user to the next under Octane.
 */
final class ScopeLock
{
    /** @var array{services: list<string>, environments: list<string>}|null */
    private ?array $resolved = null;

    public function __construct(private readonly TelemetryUiManager $manager) {}

    /**
     * Allowed services, or [] when unrestricted.
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
     * @return array{services: list<string>, environments: list<string>}
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
