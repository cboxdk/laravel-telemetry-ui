<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Events;

/**
 * A backend (Prometheus/Mimir, Tempo, Loki, a tracker) was queried. Listen to
 * meter backend load per tenant — query volume, latency, error rate — for
 * quotas or billing in a hosted deployment. Fired for every request the
 * dashboard makes; a no-op when nothing listens.
 */
final readonly class BackendQueried
{
    public function __construct(
        public string $url,
        public string $method,
        public float $durationMs,
        public bool $ok,
    ) {}
}
