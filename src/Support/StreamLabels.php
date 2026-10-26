<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\KnowsStreamLabels;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Whether the log store indexes a label as a stream label, asked once per
 * connection and cached like page detection.
 *
 * "No" is the safe answer: the label then stays a pipeline filter, which is
 * correct whether the store indexes it or not, only slower. So a driver
 * without {@see KnowsStreamLabels}, or a store that cannot answer, gets "no".
 * A failed lookup is not cached, but it is remembered for the rest of the
 * request (the binding is scoped), so a store that is down is asked once per
 * request, not once per query.
 */
final class StreamLabels
{
    /** @var array<string, list<string>|null> per connection, for this request */
    private array $known = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly CacheFactory $cache,
        private readonly int $ttl = 300,
    ) {}

    public function indexes(string $label, ?string $connection = null): bool
    {
        // Memoised per connection AND the connections in force, so a scope
        // that sees more than one set of connections never carries one
        // tenant's answer into the next.
        $name = $connection ?? 'logs';
        $key = $this->connections->cacheKey('stream-labels:'.$name);
        $labels = array_key_exists($key, $this->known)
            ? $this->known[$key]
            : $this->known[$key] = $this->labels($connection, $key);

        return $labels !== null && in_array($label, $labels, true);
    }

    /**
     * @return list<string>|null null when the store cannot say
     */
    private function labels(?string $connection, string $key): ?array
    {
        $store = $this->cache->store();
        $cached = $store->get($key);

        if (is_array($cached)) {
            return array_values(array_filter($cached, is_string(...)));
        }

        try {
            $source = $this->connections->logs($connection);

            if (! $source instanceof KnowsStreamLabels) {
                return null;
            }

            $labels = $source->streamLabels();
        } catch (SourceException) {
            return null;
        }

        $store->put($key, $labels, $this->ttl);

        return $labels;
    }
}
