<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

final readonly class TimeSeries
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<DataPoint>  $points
     */
    public function __construct(
        public array $labels,
        public array $points,
    ) {}

    /**
     * A short human label for the series: the metric name, the label set,
     * or a single label's value when one is requested.
     */
    public function name(?string $label = null): string
    {
        if ($label !== null && isset($this->labels[$label])) {
            return $this->labels[$label];
        }

        $labels = $this->labels;
        $metric = $labels['__name__'] ?? null;
        unset($labels['__name__']);

        if ($labels === []) {
            return $metric ?? 'value';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return ($metric ?? '').'{'.implode(', ', $pairs).'}';
    }

    /**
     * ECharts-friendly [[epochMillis, value], ...] pairs.
     *
     * @return list<array{float, float}>
     */
    public function toChartData(): array
    {
        return array_map(
            static fn (DataPoint $point): array => [$point->timestamp * 1000.0, $point->value],
            $this->points,
        );
    }
}
