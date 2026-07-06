<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Reverb live occupancy: active WebSocket connections per app, sampled from
 * inside the reverb:start process, plus subscribers by channel type.
 */
final class ReverbConnections extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        try {
            $range = $this->metrics()->queryRange(
                'sum by (app) ('.$this->metric('reverb_connections_active').')',
                $start,
                $end,
            );

            $active = $this->total('sum('.$this->metric('reverb_connections_active').')');
            $pruned = $this->total('sum(increase('.$this->metric('reverb_connections_pruned_total').'['.$this->promDuration().']))');
            $subscribers = $this->metrics()->query('sum by (type) ('.$this->metric('reverb_channels_subscribers').')');
        } catch (SourceException $exception) {
            return $this->chartCard('Reverb connections', error: $exception->getMessage(), span: 2);
        }

        $stats = [
            $this->stat('Active', Format::count($active)),
            $this->stat('Pruned', Format::count($pruned), $pruned > 0 ? 'warn' : 'dim'),
        ];

        foreach ($subscribers as $sample) {
            $stats[] = $this->stat(($sample->labels['type'] ?? '?').' subs', Format::count($sample->value), 'dim');
        }

        return $this->chartCard(
            title: 'Reverb connections',
            subtitle: 'Active WebSocket connections per app, with subscribers by channel type',
            series: $this->toChartSeries($range, 'app'),
            stats: $stats,
            type: 'area',
            unit: 'connections',
            span: 2,
        );
    }
}
