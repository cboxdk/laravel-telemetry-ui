<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Livewire component lifecycle throughput — mounts (first render) vs
 * hydrations (subsequent requests), from the ComponentHook counters.
 */
final class LivewireActivity extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $mounted = $this->metric('livewire_components_mounted_total');
        $hydrated = $this->metric('livewire_components_hydrated_total');

        try {
            $series = [];

            foreach (['Mounted' => [$mounted, '#34d399'], 'Hydrated' => [$hydrated, '#60a5fa']] as $label => [$metric, $color]) {
                foreach ($this->metrics()->queryRange($metric->rate($this->rateWindow())->sumBy()->times(60), $start, $end) as $timeSeries) {
                    $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
                }
            }

            $totalMounted = $this->total($mounted->increase($this->promDuration())->sumBy());
            $totalHydrated = $this->total($hydrated->increase($this->promDuration())->sumBy());
        } catch (SourceException $exception) {
            return $this->chartCard('Livewire activity', error: $exception->getMessage(), span: 2);
        }

        return $this->chartCard(
            title: 'Livewire activity',
            subtitle: 'Components mounted (first render) and hydrated (updates) per minute',
            series: $series,
            stats: [
                $this->stat('Mounted', Format::count($totalMounted)),
                $this->stat('Hydrated', Format::count($totalHydrated), 'dim'),
            ],
            type: 'area',
            unit: '/min',
            span: 2,
        );
    }
}
