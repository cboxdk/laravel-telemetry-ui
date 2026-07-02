<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

use DateTimeImmutable;

/**
 * A single Tempo search hit.
 */
final readonly class TraceSummary
{
    public function __construct(
        public string $traceId,
        public string $rootServiceName,
        public string $rootTraceName,
        public DateTimeImmutable $startedAt,
        public float $durationMs,
    ) {}
}
