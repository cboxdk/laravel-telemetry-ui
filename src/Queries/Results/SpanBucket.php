<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

/**
 * One group of a span aggregation: a distinct value of the grouped attribute
 * plus its duration statistics over every matching span (all in milliseconds).
 */
final readonly class SpanBucket
{
    /**
     * @param  array<string, string>  $attributes  carried representative attributes (e.g. `db.system.name`)
     */
    public function __construct(
        public string $key,
        public int $count,
        public float $avgMs,
        public float $p95Ms,
        public float $maxMs,
        public float $totalMs,
        public array $attributes = [],
    ) {}
}
