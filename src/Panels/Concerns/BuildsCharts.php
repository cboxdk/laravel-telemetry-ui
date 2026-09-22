<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * The chart engine shared by every {@see \Cbox\TelemetryUi\Panels\Panel}: the
 * `chart` payload (series, annotations, range bounds, error/empty states) plus
 * the terse {@see promChart()} path and the stat-tile builders — so a metric
 * panel is a query and a title.
 */
trait BuildsCharts
{
    /**
     * Convert TimeSeries results to the chart payload's series shape.
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
     * A stats-row item for the chart/stats payloads.
     *
     * @return array{label: string, value: string, tone: string|null}
     */
    protected function stat(string $label, string $value, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /**
     * A KPI tile with a period-over-period delta and an optional window
     * sparkline — the Datadog-style headline. `$upIsGood` flips the delta tone:
     * throughput up is good (green), error-rate up is bad (red). A zero/absent
     * previous value drops the delta and the tile renders plain. `$points`
     * draws an inline sparkline of the window.
     *
     * @param  list<float>  $points
     * @return array{label: string, value: string, tone: string|null, delta?: string, deltaTone?: string, points?: list<float>, sparkColor?: string}
     */
    protected function statDelta(
        string $label,
        string $value,
        float $current,
        float $previous,
        bool $upIsGood = true,
        ?string $tone = null,
        array $points = [],
    ): array {
        $item = $this->stat($label, $value, $tone);

        if ($previous > 0.0) {
            $pct = ($current - $previous) / $previous * 100.0;
            $dir = match (true) {
                $pct >= 1.0 => 'up',
                $pct <= -1.0 => 'down',
                default => 'flat',
            };
            $item['delta'] = match ($dir) {
                'up' => '▲ ',
                'down' => '▼ ',
                default => '± ',
            }.number_format(abs($pct), abs($pct) >= 10.0 ? 0 : 1).'%';
            $item['deltaTone'] = $dir === 'flat' ? 'dim' : (($dir === 'up') === $upIsGood ? 'ok' : 'danger');
        }

        if ($points !== []) {
            $item['points'] = $points;
            $item['sparkColor'] = 'var(--chart-1)';
        }

        return $item;
    }

    /**
     * A whole metric chart card in one call — the terse path for the common
     * "run a PromQL range query, draw it" card. It queries the range, converts
     * the series, catches backend errors, and renders {@see chartCard()}. Use a
     * grouped query (`sum by (x)(…)`) for multiple lines. Pass $stat to add a
     * headline tile from an instant query ($statQuery, or the same $promql).
     *
     *   public function data(): array
     *   {
     *       return $this->promChart('Queue depth', $this->metric('queue_size'), stat: 'Now');
     *   }
     *
     * @return array<string, mixed>
     */
    protected function promChart(
        string $title,
        MetricQuery $promql,
        ?string $subtitle = null,
        ?string $seriesLabel = null,
        string $type = 'line',
        ?string $unit = null,
        int $span = 1,
        ?string $stat = null,
        ?MetricQuery $statQuery = null,
    ): array {
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
     * The shared "stats + chart" payload (series, annotations, range bounds,
     * error state) — see {@see promChart()} for the terse path.
     *
     * @param  list<array{name: string, data: list<array{float, float}>, color?: string}>  $series
     * @param  list<array<string, mixed>>  $stats
     * @return array<string, mixed>
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
        ?string $empty = null,
    ): array {
        [$start, $end] = $this->range();

        // A series of all-zero points renders as a flat, broken-looking line;
        // treat "present but no activity" as empty so the card shows a clean
        // state instead. Genuine data with any non-zero point still charts.
        if ($series !== [] && ! $this->seriesHasSignal($series)) {
            $series = [];
        }

        return [
            'kind' => 'chart',
            'title' => $title,
            'subtitle' => $subtitle,
            'drill' => $this->drillLink(),
            'series' => $series,
            'stats' => $stats,
            'type' => $type,
            'unit' => $unit,
            'error' => $error,
            'span' => $span,
            'note' => $note,
            'empty' => $empty,
            'height' => $height,
            'annotations' => $annotate && $series !== [] ? $this->annotationMarks() : [],
            'min' => $start->getTimestamp() * 1000,
            'max' => $end->getTimestamp() * 1000,
        ];
    }

    /**
     * The header drill link for panels that summarise a dedicated page —
     * shown on the dashboard only, so a panel on its own page stays quiet.
     *
     * @return array<string, mixed>|null
     */
    private function drillLink(): ?array
    {
        if ($this->drillPage === null || $this->onPage !== 'dashboard') {
            return null;
        }

        $meta = app(TelemetryUiManager::class)->pages()[$this->drillPage] ?? null;

        return [...$this->pageLink($this->drillPage), 'label' => $meta['label'] ?? $this->drillPage];
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
