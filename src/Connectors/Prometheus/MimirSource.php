<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Prometheus;

use Cbox\TelemetryUi\Connectors\ApiClient;

/**
 * Grafana Mimir speaks the Prometheus HTTP API under the "/prometheus"
 * path prefix, with multi-tenancy handled by the ApiClient's tenant header.
 *
 * Window-wide reads over six hours run as sliced range queries by default:
 * Mimir's query-frontend splits and caches a range query by day, while an
 * instant query fetches the whole window's chunks at once and is the first
 * thing over `max_fetched_chunks_per_query` (see WindowSplitter).
 */
class MimirSource extends PrometheusSource
{
    public const SPLIT_AFTER = 21_600;

    public function __construct(ApiClient $client, string $prefix = 'prometheus', ?WindowSplitter $splitter = null)
    {
        parent::__construct($client, $prefix, $splitter ?? new WindowSplitter(self::SPLIT_AFTER));
    }
}
