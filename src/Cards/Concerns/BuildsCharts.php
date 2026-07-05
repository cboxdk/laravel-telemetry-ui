<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Concerns;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The chart engine shared by every {@see Card}: the
 * ECharts card view (annotations, zoom, lazy skeleton, error/empty states) plus
 * the terse {@see promChart()} path and the stat-tile builders — so a metric
 * card is a query and a title, not its own Blade file.
 */
trait BuildsCharts
{
    /**
     * Convert TimeSeries results to the shape the <x-telemetry-ui::chart>
     * component feeds to ECharts.
     *
     * @param  list<TimeSeries>  $series
     * @return list<array{name: string, data: list<array{float, float}>}>
     */
    protected function toChartSeries(array $series, ?string $label = null): array
    {
        return array_map(static fn (TimeSeries $timeSeries): array => [
            'name' => $timeSeries->name($label),
            'data' => $timeSeries->toChartData(),
        ], $series);
    }

    /**
     * A stats-row item for the generic chart card / <x-telemetry-ui::stats>.
     *
     * @return array{label: string, value: string, tone: string|null}
     */
    protected function stat(string $label, string $value, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /**
     * A whole metric chart card in one call — the terse path for the common
     * "run a PromQL range query, draw it" card. It queries the range, converts
     * the series, catches backend errors, and renders {@see chartCard()}. Use a
     * grouped query (`sum by (x)(…)`) for multiple lines. Pass $stat to add a
     * headline tile from an instant query ($statQuery, or the same $promql).
     *
     *   public function render(): View
     *   {
     *       return $this->promChart('Queue depth', $this->metric('queue_size'), stat: 'Now');
     *   }
     */
    protected function promChart(
        string $title,
        string $promql,
        ?string $subtitle = null,
        ?string $seriesLabel = null,
        string $type = 'line',
        ?string $unit = null,
        int $span = 1,
        ?string $stat = null,
        ?string $statQuery = null,
    ): View {
        [$start, $end] = $this->range();

        try {
            $series = $this->toChartSeries($this->metrics()->queryRange($promql, $start, $end), $seriesLabel);
            $stats = $stat !== null ? [$this->stat($stat, $this->formatValue($this->total($statQuery ?? $promql), $unit))] : [];
        } catch (SourceException $exception) {
            return $this->chartCard($title, error: $exception->getMessage(), span: $span, subtitle: $subtitle);
        }

        return $this->chartCard($title, series: $series, stats: $stats, type: $type, unit: $unit, span: $span, subtitle: $subtitle);
    }

    /**
     * Render the shared "stats + chart" card view (ECharts, annotations, zoom,
     * lazy skeleton, error state) — most metric cards use this instead of
     * shipping their own Blade file. See {@see promChart()} for the terse path.
     *
     * @param  list<array{name: string, data: list<array{float, float}>, color?: string}>  $series
     * @param  list<array{label: string, value: string, tone: string|null}>  $stats
     */
    protected function chartCard(
        string $title,
        array $series = [],
        array $stats = [],
        string $type = 'line',
        ?string $unit = null,
        ?string $error = null,
        int $span = 1,
        ?string $note = null,
        int $height = 200,
        bool $annotate = true,
        ?string $subtitle = null,
    ): View {
        [$start, $end] = $this->range();

        // A series of all-zero points renders as a flat, broken-looking line;
        // treat "present but no activity" as empty so the card shows a clean
        // state instead. Genuine data with any non-zero point still charts.
        if ($series !== [] && ! $this->seriesHasSignal($series)) {
            $series = [];
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.chart';

        return view($view, [
            'title' => $title,
            'subtitle' => $subtitle,
            'series' => $series,
            'stats' => $stats,
            'type' => $type,
            'unit' => $unit,
            'error' => $error,
            'span' => $span,
            'note' => $note,
            'height' => $height,
            'annotations' => $annotate && $series !== [] ? $this->annotationMarks() : [],
            'min' => $start->getTimestamp() * 1000,
            'max' => $end->getTimestamp() * 1000,
        ]);
    }

    /**
     * Format a metric value for a stat tile, picking the formatter from the
     * chart's unit.
     */
    private function formatValue(float $value, ?string $unit): string
    {
        return match ($unit) {
            'bytes' => Format::bytes($value),
            'ms', 'milliseconds' => Format::ms($value),
            'ratio', 'percent' => Format::percent($value),
            default => Format::count($value),
        };
    }

    /**
     * Whether any series carries a non-zero data point.
     *
     * @param  list<array{name: string, data: list<array{float, float}>, color?: string}>  $series
     */
    private function seriesHasSignal(array $series): bool
    {
        foreach ($series as $entry) {
            foreach ($entry['data'] as $point) {
                if (($point[1] ?? 0.0) != 0.0) {
                    return true;
                }
            }
        }

        return false;
    }
}
