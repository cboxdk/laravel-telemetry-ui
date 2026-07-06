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
 * queue_autoscale.scaling.actions, whose direction label is 'up' | 'down'
 * (the WorkersScaled action, verified against live data).
 */
final class AutoscaleActions extends Card
{
    protected ?string $drillPage = 'autoscale';

    private const DIRECTIONS = [
        'up' => ['Scale up', '#34d399'],
        'down' => ['Scale down', '#60a5fa'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('queue_autoscale_scaling_actions_total');

        try {
            $totals = [];

            foreach ($this->metrics()->query('sum by (direction) (increase('.$metric.'['.$this->promDuration().']))') as $sample) {
                $direction = $sample->labels['direction'] ?? '';
                $totals[$direction] = ($totals[$direction] ?? 0.0) + $sample->value;
            }

            $range = $this->metrics()->queryRange(
                'sum by (direction) (increase('.$metric.'['.$this->rateWindow().']))',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Scaling actions', error: $exception->getMessage());
        }

        $series = [];
        $stats = [];

        foreach (self::DIRECTIONS as $direction => [$label, $color]) {
            $total = $totals[$direction] ?? 0.0;
            $stats[] = $this->stat($label, Format::count($total), $total > 0 ? null : 'dim');

            foreach ($range as $timeSeries) {
                if (($timeSeries->labels['direction'] ?? '') === $direction) {
                    $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
                }
            }
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
