<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use DateTimeInterface;

/**
 * A {@see TracesSource} that can aggregate spans server-side — group by an
 * attribute with count/avg/p95/max/total over EVERY matching span, not a
 * sample. Tempo can't do this reliably for high-cardinality attributes (query
 * text, url), so it's an optional capability a card feature-detects, the way
 * {@see CreatesIssues} extends {@see IssuesSource}: present on a ClickHouse
 * store, absent on Tempo — which falls back to sampling read-side.
 *
 * @api Implement alongside TracesSource to unlock exact query/span analytics.
 */
interface AggregatesSpans
{
    /**
     * @return list<SpanBucket>
     */
    public function aggregateSpans(SpanAggregation $aggregation, DateTimeInterface $start, DateTimeInterface $end): array;
}
