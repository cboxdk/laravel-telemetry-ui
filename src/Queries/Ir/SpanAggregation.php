<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

use Cbox\TelemetryUi\Contracts\AggregatesSpans;

/**
 * A server-side span aggregation: group the spans matching {@see $where} by the
 * {@see $groupBy} attribute and return per-group duration stats — the exact
 * equivalent of the read-side sample-and-fold, done over EVERY matching span.
 * Executed by a {@see AggregatesSpans} driver.
 */
final readonly class SpanAggregation
{
    /**
     * @param  string  $groupBy  the span/resource attribute to group on (e.g. `span.db.query.text`)
     * @param  list<string>  $carry  attributes to return alongside each group (one representative value)
     */
    public function __construct(
        public TraceQuery $where,
        public string $groupBy,
        public float $quantile = 0.95,
        public array $carry = [],
        public int $limit = 100,
        public SpanSort $sort = SpanSort::Total,
    ) {}
}
