<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Auth;

/**
 * Request-scoped memo of the per-viewer connection resolver
 * ({@see TelemetryUiManager::resolveConnectionsUsing()}). A page renders many
 * cards, each resolving several backends, so the resolver — often a DB/tenant
 * lookup — must run once per request, not once per backend access. Bound
 * scoped, so one request's resolved map never leaks to the next under Octane.
 */
final class ResolvedConnections
{
    /** @var array<string, mixed>|null */
    private ?array $map = null;

    private bool $resolved = false;

    public function __construct(private readonly TelemetryUiManager $manager) {}

    /**
     * The resolved config for a named connection, or null when there is no
     * resolver, no authenticated viewer, or the resolver omits it (the caller
     * then falls back to static config).
     *
     * @return array<string, mixed>|null
     */
    public function config(string $name): ?array
    {
        $config = $this->map()[$name] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(): array
    {
        if ($this->resolved) {
            return $this->map ?? [];
        }

        $resolver = $this->manager->connectionResolver();
        $user = Auth::user();

        // Only consult the resolver for an actual viewer — an unauthenticated or
        // boot-time context (e.g. the Issues-page registration) has no tenant, so
        // it falls through to static config (no null deref, no resolve at boot).
        // Crucially, DON'T memoise that empty result: the same scoped instance is
        // touched at boot, and freezing it there would blind the later
        // authenticated request. Memoise only once a real resolution happens.
        if ($resolver === null || $user === null) {
            return [];
        }

        $this->resolved = true;
        $resolved = $resolver($user);
        $this->map = is_array($resolved) ? $resolved : [];

        return $this->map;
    }
}
