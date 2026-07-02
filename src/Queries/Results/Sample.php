<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

/**
 * A single instant-query result (one element of a Prometheus vector).
 */
final readonly class Sample
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public array $labels,
        public float $timestamp,
        public float $value,
    ) {}
}
