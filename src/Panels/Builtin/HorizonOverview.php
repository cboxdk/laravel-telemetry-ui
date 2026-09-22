<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Format;

/**
 * Horizon fleet health: worker processes per supervisor (gauges pushed from
 * the supervisor/master heartbeat) plus paused state at a glance.
 */
final class HorizonOverview extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        try {
            $range = $this->metrics()->queryRange(
                $this->metric('horizon_supervisor_processes')->sumBy('supervisor'),
                $start,
                $end,
            );

            $processes = $this->total($this->sumQuery('horizon_supervisor_processes'));
            $paused = $this->total($this->sumQuery('horizon_supervisor_paused'));
            $supervisors = $this->total($this->sumQuery('horizon_master_supervisors'));
        } catch (SourceException $exception) {
            return $this->chartCard('Horizon workers', error: $exception->getMessage(), span: 2);
        }

        return $this->chartCard(
            title: 'Horizon workers',
            subtitle: 'Active worker processes per supervisor, from the Horizon heartbeat',
            series: $this->toChartSeries($range, 'supervisor'),
            stats: [
                $this->stat('Processes', Format::count($processes)),
                $this->stat('Supervisors', Format::count($supervisors), 'dim'),
                $this->stat('Paused', Format::count($paused), $paused > 0 ? 'warn' : 'ok'),
            ],
            type: 'area',
            unit: 'processes',
            span: 2,
        );
    }

    private function sumQuery(string $metric): MetricQuery
    {
        return $this->metric($metric)->sumBy();
    }
}
