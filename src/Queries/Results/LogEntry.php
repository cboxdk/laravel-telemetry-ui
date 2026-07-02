<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

use DateTimeImmutable;

final readonly class LogEntry
{
    /**
     * @param  array<string, string>  $labels  the Loki stream labels this entry belongs to
     */
    public function __construct(
        public int $timestampNano,
        public string $line,
        public array $labels,
    ) {}

    public function timestamp(): DateTimeImmutable
    {
        $seconds = intdiv($this->timestampNano, 1_000_000_000);
        $micros = intdiv($this->timestampNano % 1_000_000_000, 1_000);

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros))
            ?: new DateTimeImmutable('@'.$seconds);
    }
}
