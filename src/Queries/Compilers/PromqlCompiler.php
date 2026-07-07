<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Compilers;

use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\MetricAgg;
use Cbox\TelemetryUi\Queries\Ir\MetricFn;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

/**
 * Compiles a {@see MetricQuery} to a PromQL string for Prometheus/Mimir. Label
 * matchers are quoted and `"`/`\` escaped here; raw matcher fragments and
 * raw queries pass through verbatim.
 */
final class PromqlCompiler
{
    public function compile(MetricQuery $query): string
    {
        if ($query->raw !== null) {
            return $query->raw;
        }

        $selector = $this->selector($query);

        if ($query->quantile !== null) {
            $by = [...$query->by, 'le'];

            return 'histogram_quantile('.self::number($query->quantile)
                .', sum by ('.implode(', ', $by).') (rate('.$selector.'['.$query->window.'])))';
        }

        $expr = $this->inner($query, $selector);

        if ($query->agg !== MetricAgg::None) {
            $expr = $query->by === []
                ? $query->agg->value.'('.$expr.')'
                : $query->agg->value.' by ('.implode(', ', $query->by).') ('.$expr.')';
        }

        if ($query->scalar !== null) {
            $expr .= ' * '.self::number($query->scalar);
        }

        return $expr;
    }

    private function selector(MetricQuery $query): string
    {
        $matchers = [
            ...array_map($this->matcher(...), $query->matchers),
            ...$query->rawMatchers,
        ];

        return $matchers === [] ? $query->name : $query->name.'{'.implode(',', $matchers).'}';
    }

    private function inner(MetricQuery $query, string $selector): string
    {
        return match ($query->fn) {
            MetricFn::None => $selector,
            MetricFn::Rate => 'rate('.$selector.'['.$query->window.'])',
            MetricFn::Increase => 'increase('.$selector.'['.$query->window.'])',
            MetricFn::CounterIncrease => 'clamp_min('.$selector.' - ('.$selector.' offset '.$query->window.' or '.$selector.' * 0), 0)',
        };
    }

    private function matcher(LabelMatcher $matcher): string
    {
        return $matcher->label.$matcher->op->value.'"'.addcslashes($matcher->value, '"\\').'"';
    }

    /**
     * Render a float without a trailing `.0`, so `60.0` → `60` and `0.95`
     * stays `0.95` — matching the hand-written PromQL the cards used to emit.
     */
    private static function number(float $value): string
    {
        return $value === floor($value) && is_finite($value)
            ? (string) (int) $value
            : (string) $value;
    }
}
