<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Executed scaling actions over time, split by direction — how often (and
 * which way) the autoscaler actually moved. From
 * queue_autoscale.scaling.actions.
 */
final class AutoscaleActions extends Card
{
    private const DIRECTIONS = [
        'scale_up' => ['Scale up', '#34d399'],
        'scale_down' => ['Scale down', '#60a5fa'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        try {
            foreach (self::DIRECTIONS as $direction => [$label, $color]) {
                $metric = $this->metric('queue_autoscale_scaling_actions_total', 'direction="'.$direction.'"');

                $total = $this->total('sum(increase('.$metric.'['.$p.']))');
                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? null : 'dim');

                $range = $this->metrics()->queryRange('sum(increase('.$metric.'['.$w.']))', $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Scaling actions', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Scaling actions',
            subtitle: 'Worker scale-ups and scale-downs the autoscaler executed',
            series: $series,
            stats: $stats,
            type: 'bar',
            unit: 'actions',
        );
    }
}
