<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Glide image generations per preset (ad-hoc params collapse to "custom").
 */
final class GlideGenerations extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('statamic_glide_generations_total');

        try {
            $total = $this->total('sum(increase('.$metric.'['.$this->promDuration().']))');
            $range = $this->metrics()->queryRange('sum by (preset) (rate('.$metric.'['.$this->rateWindow().'])) * 60', $start, $end);
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
