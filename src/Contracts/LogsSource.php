<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeInterface;

/**
 * A LogQL-capable log backend (Loki, ...).
 */
interface LogsSource
{
    /**
     * Run a log query over a time range. Entries are returned in
     * ascending timestamp order regardless of the query direction.
     *
     * @return list<LogEntry>
     */
    public function query(
        string $logql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 100,
    ): array;

    /**
     * List the known values of a stream label.
     *
     * @return list<string>
     */
    public function labelValues(
        string $label,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array;
}
