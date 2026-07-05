<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Discovers the fleet behind the backends: which services report, and
 * which deployment environments exist. Drives the sidebar switcher.
 */
final readonly class Fleet
{
    public function __construct(
        private ConnectionManager $connections,
        private CacheFactory $cache,
        private int $ttl = 60,
    ) {}

    /**
     * @return list<string>
     */
    public function services(): array
    {
        $lock = app(ScopeLock::class);

        return $this->restrict($this->labelValues('service_name'), $lock->services(), $lock->servicesLocked());
    }

    /**
     * @return list<string>
     */
    public function environments(): array
    {
        $lock = app(ScopeLock::class);

        return $this->restrict($this->labelValues('deployment_environment_name'), $lock->environments(), $lock->environmentsLocked());
    }

    /**
     * Keep only the discovered values the viewer is allowed to see. Not locked:
     * everything discovered. Locked to a set: the discovered values within it —
     * but if discovery came back empty (a transient backend blip, or a service
     * that hasn't reported yet), fall back to the allowed set so the switcher is
     * never empty for a locked viewer. Locked to nothing: nothing.
     *
     * @param  list<string>  $discovered
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function restrict(array $discovered, array $allowed, bool $locked): array
    {
        if (! $locked) {
            return $discovered;
        }

        if ($allowed === []) {
            return [];
        }

        $visible = array_values(array_filter($discovered, static fn (string $value): bool => in_array($value, $allowed, true)));

        return $visible === [] ? $allowed : $visible;
    }

    /**
     * @return list<string>
     */
    private function labelValues(string $label): array
    {
        /** @var list<string> $values */
        $values = $this->cache->store()->remember(
            'telemetry-ui:fleet:'.$label,
            $this->ttl,
            function () use ($label): array {
                try {
                    $values = $this->connections->metrics()->labelValues($label);
                } catch (SourceException) {
                    return [];
                }

                sort($values);

                return $values;
            },
        );

        return $values;
    }
}
