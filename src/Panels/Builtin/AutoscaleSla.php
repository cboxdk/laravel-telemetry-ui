<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * SLA health: predicted job pickup time per queue, with the queues currently
 * in breach and the breach transitions over the period. From
 * queue_autoscale.sla.predicted_pickup, .sla.breach and .sla.breaches.
 */
final class AutoscaleSla extends Panel
{
    protected ?string $drillPage = 'autoscale';

    public function data(): array
    {
        [$start, $end] = $this->range();

        $predicted = $this->metric('queue_autoscale_sla_predicted_pickup_seconds');

        try {
            $inBreach = $this->total($this->metric('', '__name__=~"queue_autoscale_sla_breach(_ratio)?"')->sumBy());
            // counterIncrease(), not increase(): breaches are rare, and a
            // counter born mid-window would otherwise read as zero.
            $breaches = $this->total(
                $this->counterIncrease($this->metric('queue_autoscale_sla_breaches_total'))->sumBy(),
            );

            $range = $this->metrics()->queryRange($predicted->maxBy('queue'), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('SLA', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'SLA',
            subtitle: 'Predicted job pickup time per queue, against each queue\'s SLA target',
            series: $this->toChartSeries($range, 'queue'),
            stats: [
                $this->stat('In breach', Format::count($inBreach), $inBreach > 0 ? 'danger' : 'ok'),
                $this->stat('Breaches', Format::count($breaches), $breaches > 0 ? 'danger' : 'dim'),
            ],
            unit: 's',
        );
    }
}
