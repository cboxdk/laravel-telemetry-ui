<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

/**
 * A collapsed view of one context signal over a window: the load-bearing
 * numbers (last/avg/max) plus points for a sparkline, and the `baseline` —
 * the typical value for this scope over a longer lookback — so we can answer
 * "what was different?" (this request ran while the host was at 95% CPU, and
 * it's usually 30%). Deliberately headless: the same summary feeds the trace
 * context strip, the comparison badges, the (future) MCP tools and AI chat.
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
        public ?float $baseline = null,
    ) {}

    /**
     * Materially above its usual level for this scope — the "this was
     * different" flag. 50%+ over baseline (guards tiny/zero baselines).
     */
    public function isOutlier(): bool
    {
        return $this->baseline !== null
            && $this->baseline > 0.0
            && $this->current >= $this->baseline * 1.5;
    }
}
