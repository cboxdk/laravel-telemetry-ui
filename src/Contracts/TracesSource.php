<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeInterface;

/**
 * A TraceQL-capable tracing backend (Tempo, ...).
 */
interface TracesSource
{
    /**
     * Search for traces matching a TraceQL expression.
     *
     * @return list<TraceSummary>
     */
    public function search(
        string $traceql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 20,
    ): array;

    /**
     * Fetch a full trace by id.
     */
    public function trace(string $traceId): Trace;

    /**
     * List the known values of a span/resource tag, optionally restricted
     * by a TraceQL filter (e.g. values of .http.route within a service).
     *
     * @return list<string>
     */
    public function tagValues(string $tag, ?string $traceql = null): array;
}
