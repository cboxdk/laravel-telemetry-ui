<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Detects which schema families the connected backends actually contain,
 * so pages contributed for optional emitters (statamic-telemetry, queue
 * autoscalers, ...) only appear when their metrics exist.
 *
 * Detection is a single cached instant query per pattern. Backend failures
 * fail open (the page stays visible and renders its own error states) and
 * are never cached.
 */
final readonly class SchemaDetector
{
    public function __construct(
        private ConnectionManager $connections,
        private CacheFactory $cache,
        private int $ttl = 300,
    ) {}

    /**
     * Whether any metric matching the regex pattern exists (e.g. "statamic_.*").
     */
    public function hasMetricsMatching(string $pattern, ?string $connection = null): bool
    {
        $key = 'telemetry-ui:detect:'.($connection ?? 'metrics').':'.$pattern;

        $store = $this->cache->store();

        $cached = $store->get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $samples = $this->connections->metrics($connection)->query(
                sprintf('count({__name__=~"%s"})', $pattern),
            );
        } catch (SourceException) {
            return true;
        }

        $found = $samples !== [] && $samples[0]->value > 0;

        $store->put($key, $found, $this->ttl);

        return $found;
    }
}
