<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeInterface;

/**
 * A tracing backend (Tempo, a ClickHouse store, ...).
 *
 * @api Implement to add a traces driver; cards depend only on this contract.
 *      Each driver compiles the backend-neutral {@see TraceQuery} to its own
 *      dialect.
 */
interface TracesSource
{
    /**
     * Search for traces matching a query.
     *
     * @return list<TraceSummary>
     */
    public function search(
        TraceQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 20,
    ): array;

    /**
     * Fetch a full trace by id.
     */
    public function trace(string $traceId): Trace;

    /**
     * List the known values of a span/resource tag, optionally restricted by a
     * query filter (e.g. values of .http.route within a service) and a time
     * window + result limit so it doesn't scan the whole backend retention.
     *
     * @return list<string>
     */
    public function tagValues(
        string $tag,
        ?TraceQuery $filter = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
        int $limit = 0,
    ): array;
}
