<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

/**
 * A collapsed view of one context signal over a window: the load-bearing
 * numbers (last/avg/max) plus points for a sparkline. Deliberately headless —
 * the same summary feeds the trace context strip, the comparison view, the
 * (future) MCP tools and AI chat.
 */
final readonly class MetricSummary
{
    /**
     * @param  'host'|'runtime'|'db'|'cache'|'custom'  $group
     * @param  list<float>  $points
     */
    public function __construct(
        public string $label,
        public string $group,
        public string $unit,
        public float $current,
        public float $avg,
        public float $max,
        public array $points,
    ) {}
}
