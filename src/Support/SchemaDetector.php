<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
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
     * Whether any metric matching the regex pattern exists (e.g. "statamic_.*"),
     * optionally within a PromQL scope (e.g. `service_name="checkout"`) so the
     * question becomes "does the SELECTED service emit these?" — see
     * {@see MetricScope}. Scope varies the cache key; an empty scope keeps the
     * original fleet-wide key and query.
     */
    public function hasMetricsMatching(string $pattern, string $scope = '', ?string $connection = null): bool
    {
        $key = 'telemetry-ui:detect:'.($connection ?? 'metrics').':'.($scope !== '' ? $scope.':' : '').$pattern;

        $store = $this->cache->store();

        $cached = $store->get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        $selector = '__name__=~"'.$pattern.'"'.($scope !== '' ? ','.$scope : '');

        try {
            $samples = $this->connections->metrics($connection)->query(
                MetricQuery::raw(sprintf('count({%s})', $selector)),
            );
        } catch (SourceException) {
            return true;
        }

        $found = $samples !== [] && $samples[0]->value > 0;

        $store->put($key, $found, $this->ttl);

        return $found;
    }
}
