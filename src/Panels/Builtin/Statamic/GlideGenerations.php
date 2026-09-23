<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Glide image generations per preset (ad-hoc params collapse to "custom").
 */
final class GlideGenerations extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('statamic_glide_generations_total');

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());
            $range = $this->metrics()->queryRange($metric->rate($this->rateWindow())->sumBy('preset')->times(60), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Glide', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Glide generations',
            series: $this->toChartSeries($range, 'preset'),
            stats: [$this->stat('Generated', Format::count($total))],
            type: 'bar',
            unit: '/min',
        );
    }
}
