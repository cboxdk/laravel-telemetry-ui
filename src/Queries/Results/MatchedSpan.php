<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

/**
 * A span matched by a TraceQL search (from the response's spanSets),
 * including any attributes the query select()ed.
 */
final readonly class MatchedSpan
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $spanId,
        public string $name,
        public int $startNano,
        public float $durationMs,
        public array $attributes,
    ) {}
}
