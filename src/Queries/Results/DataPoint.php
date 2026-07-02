<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

final readonly class DataPoint
{
    public function __construct(
        public float $timestamp,
        public float $value,
    ) {}
}
