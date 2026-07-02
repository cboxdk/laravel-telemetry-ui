<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

final readonly class Span
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public string $serviceName,
        public SpanKind $kind,
        public int $startNano,
        public int $endNano,
        public array $attributes,
        public bool $hasError,
    ) {}

    public function durationMs(): float
    {
        return ($this->endNano - $this->startNano) / 1_000_000;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }
}
