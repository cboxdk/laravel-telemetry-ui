<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;

/**
 * A backend-neutral metric query: a scoped selector, an optional range function
 * (rate/increase/…), an optional aggregation `by` labels, and either a
 * histogram-quantile or a scalar multiplier. Cards build one from
 * {@see ScopesQueries::metric()} and the fluent
 * helpers here; each metrics driver compiles it to its own dialect (PromQL for
 * Prometheus/Mimir, SQL for a ClickHouse store).
 *
 * Derived-label tricks (PromQL `label_replace` to bucket status codes, etc.)
 * are intentionally NOT modelled — cards group by the raw label and bucket in
 * PHP. {@see raw()} carries a hand-written PromQL string verbatim for
 * config-driven exporter queries (system/host cards); a SQL backend may reject
 * it.
 */
final readonly class MetricQuery
{
    /**
     * @param  list<LabelMatcher>  $matchers  structured scope matchers (service/env)
     * @param  list<string>  $rawMatchers  verbatim matcher fragments (entity scope, extra)
     * @param  list<string>  $by  aggregation group-by labels
     */
    public function __construct(
        public string $name,
        public array $matchers = [],
        public array $rawMatchers = [],
        public MetricFn $fn = MetricFn::None,
        public string $window = '',
        public MetricAgg $agg = MetricAgg::None,
        public array $by = [],
        public ?float $quantile = null,
        public ?float $scalar = null,
        public ?string $raw = null,
    ) {}

    /**
     * A verbatim PromQL string (escape hatch) — for config-driven exporter
     * queries. Backends that can't parse PromQL should raise rather than guess.
     */
    public static function raw(string $promql): self
    {
        return new self('', raw: $promql);
    }

    public function rate(string $window): self
    {
        return $this->with(fn: MetricFn::Rate, window: $window);
    }

    public function increase(string $window): self
    {
        return $this->with(fn: MetricFn::Increase, window: $window);
    }

    public function counterIncrease(string $window): self
    {
        return $this->with(fn: MetricFn::CounterIncrease, window: $window);
    }

    public function sumBy(string ...$by): self
    {
        return $this->with(agg: MetricAgg::Sum, by: array_values($by));
    }

    public function avgBy(string ...$by): self
    {
        return $this->with(agg: MetricAgg::Avg, by: array_values($by));
    }

    public function maxBy(string ...$by): self
    {
        return $this->with(agg: MetricAgg::Max, by: array_values($by));
    }

    public function minBy(string ...$by): self
    {
        return $this->with(agg: MetricAgg::Min, by: array_values($by));
    }

    public function countBy(string ...$by): self
    {
        return $this->with(agg: MetricAgg::Count, by: array_values($by));
    }

    /**
     * A histogram quantile over the selector: `histogram_quantile(q, sum by
     * (<by>, le) (rate(<selector>[window])))`. The `le` grouping is implicit.
     */
    public function quantile(float $q, string $window, string ...$by): self
    {
        return $this->with(quantile: $q, window: $window, by: array_values($by));
    }

    /** Multiply the result by a scalar (e.g. `* 60` for per-second → per-minute). */
    public function times(float $scalar): self
    {
        return $this->with(scalar: $scalar);
    }

    /**
     * @param  list<LabelMatcher>|null  $matchers
     * @param  list<string>|null  $rawMatchers
     * @param  list<string>|null  $by
     */
    private function with(
        ?array $matchers = null,
        ?array $rawMatchers = null,
        ?MetricFn $fn = null,
        ?string $window = null,
        ?MetricAgg $agg = null,
        ?array $by = null,
        ?float $quantile = null,
        ?float $scalar = null,
    ): self {
        return new self(
            name: $this->name,
            matchers: $matchers ?? $this->matchers,
            rawMatchers: $rawMatchers ?? $this->rawMatchers,
            fn: $fn ?? $this->fn,
            window: $window ?? $this->window,
            agg: $agg ?? $this->agg,
            by: $by ?? $this->by,
            quantile: $quantile ?? $this->quantile,
            scalar: $scalar ?? $this->scalar,
            raw: $this->raw,
        );
    }
}
