<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Stache warms and clears over time, with warm-build p95.
 */
final class StacheActivity extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $warms = $this->metric('statamic_stache_warms_total');
        $clears = $this->metric('statamic_stache_clears_total');
        $bucket = $this->metric('statamic_stache_warm_duration_milliseconds_bucket');
        $w = $this->rateWindow();
        $p = $this->promDuration();

        try {
            $totalWarms = $this->total($warms->increase($p)->sumBy());
            $totalClears = $this->total($clears->increase($p)->sumBy());
            $p95 = $this->total($bucket->quantile(0.95, $p));

            $warmRange = $this->metrics()->queryRange($warms->rate($w)->sumBy()->times(60), $start, $end);
            $clearRange = $this->metrics()->queryRange($clears->rate($w)->sumBy()->times(60), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Stache', error: $exception->getMessage());
        }

        $series = [];

        if (isset($warmRange[0])) {
            $series[] = ['name' => 'Warms', 'data' => $warmRange[0]->toChartData(), 'color' => '#34d399'];
        }

        if (isset($clearRange[0])) {
            $series[] = ['name' => 'Clears', 'data' => $clearRange[0]->toChartData(), 'color' => '#fbbf24'];
        }

        return $this->chartCard(
            title: 'Stache',
            series: $series,
            stats: [
                $this->stat('Warms', Format::count($totalWarms), 'ok'),
                $this->stat('Clears', Format::count($totalClears), $totalClears > 0 ? 'warn' : 'dim'),
                $this->stat('Warm P95', is_nan($p95) ? '—' : Format::ms($p95), 'dim'),
            ],
            type: 'bar',
            unit: '/min',
        );
    }
}
