<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

final readonly class Trace
{
    /**
     * @param  list<Span>  $spans  ordered by start time
     */
    public function __construct(
        public string $traceId,
        public array $spans,
    ) {}

    public function root(): ?Span
    {
        foreach ($this->spans as $span) {
            if ($span->isRoot()) {
                return $span;
            }
        }

        return $this->spans[0] ?? null;
    }

    /**
     * @return list<Span>
     */
    public function children(Span $parent): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn (Span $span): bool => $span->parentSpanId === $parent->spanId,
        ));
    }

    public function durationMs(): float
    {
        if ($this->spans === []) {
            return 0.0;
        }

        $start = min(array_map(static fn (Span $span): int => $span->startNano, $this->spans));
        $end = max(array_map(static fn (Span $span): int => $span->endNano, $this->spans));

        return ($end - $start) / 1_000_000;
    }

    public function hasError(): bool
    {
        foreach ($this->spans as $span) {
            if ($span->hasError) {
                return true;
            }
        }

        return false;
    }
}
