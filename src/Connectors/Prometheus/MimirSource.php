<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Prometheus;

use Cbox\TelemetryUi\Connectors\ApiClient;

/**
 * Grafana Mimir speaks the Prometheus HTTP API under the "/prometheus"
 * path prefix, with multi-tenancy handled by the ApiClient's tenant header.
 */
final class MimirSource extends PrometheusSource
{
    public function __construct(ApiClient $client, string $prefix = 'prometheus')
    {
        parent::__construct($client, $prefix);
    }
}
