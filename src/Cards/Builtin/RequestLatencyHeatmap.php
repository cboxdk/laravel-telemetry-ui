<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Request-latency heatmap: the full distribution over time, not just the p95
 * line. Colour = how many requests landed in each latency band per minute, so
 * a slow tail or a bimodal split is visible where a single percentile hides it.
 */
class RequestLatencyHeatmap extends Card
{
    protected ?string $drillPage = 'requests';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $bucket = $this->metric('http_server_request_duration_seconds_bucket');

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-latency-heatmap';

        try {
            $range = $this->metrics()->queryRange(
                $bucket->rate($this->rateWindow())->sumBy('le')->times(60),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return view($view, ['heatmap' => null, 'error' => $exception->getMessage()]);
        }

        return view($view, [
            'heatmap' => $this->buildHeatmap($range),
            'error' => null,
        ]);
    }

    /**
     * Turn the per-`le` cumulative bucket series into heatmap cells: for each
     * time column, the rate that fell in each latency band (le[i] − le[i−1]).
     *
     * @param  list<TimeSeries>  $range
     * @return array{x: list<int>, y: list<string>, cells: list<array{int, int, float}>, max: float}|null
     */
    private function buildHeatmap(array $range): ?array
    {
        // Float `le` can't key a PHP array (floats cast to int), so keep the
        // raw label and carry a numeric sort value alongside it.
        $bands = [];

        foreach ($range as $series) {
            $le = $series->labels['le'] ?? '';

            if ($le === '') {
                continue;
            }

            $points = [];

            foreach ($series->points as $point) {
                $points[(int) $point->timestamp] = $point->value;
            }

            $bands[] = ['le' => $le, 'sort' => $le === '+Inf' ? INF : (float) $le, 'points' => $points];
        }

        if ($bands === []) {
            return null;
        }

        usort($bands, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $timestampSet = [];

        foreach ($bands as $band) {
            foreach (array_keys($band['points']) as $timestamp) {
                $timestampSet[$timestamp] = true;
            }
        }

        $timestamps = array_keys($timestampSet);
        sort($timestamps);

        $cells = [];
        $labels = [];
        $max = 0.0;

        foreach ($bands as $index => $band) {
            $lower = $index > 0 ? $bands[$index - 1] : null;
            $labels[] = $this->bandLabel($lower['le'] ?? null, $band['le']);

            foreach ($timestamps as $column => $timestamp) {
                $inBand = ($band['points'][$timestamp] ?? 0.0) - ($lower['points'][$timestamp] ?? 0.0);

                if ($inBand < 0.5) {
                    continue;
                }

                $cells[] = [$column, $index, round($inBand, 2)];
                $max = max($max, $inBand);
            }
        }

        if ($cells === []) {
            return null;
        }

        return [
            'x' => array_map(static fn (int $timestamp): int => $timestamp * 1000, $timestamps),
            'y' => $labels,
            'cells' => $cells,
            'max' => $max,
        ];
    }

    // v2's histogram `le` bounds are in SECONDS; ×1000 to label the bands in ms.
    private function bandLabel(?string $lower, string $upper): string
    {
        if ($upper === '+Inf') {
            return '> '.Format::ms((float) ($lower ?? '0') * 1000);
        }

        if ($lower === null) {
            return '≤ '.Format::ms((float) $upper * 1000);
        }

        return Format::ms((float) $lower * 1000).'–'.Format::ms((float) $upper * 1000);
    }
}
