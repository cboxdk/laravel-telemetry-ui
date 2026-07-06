<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Horizon fleet health: worker processes per supervisor (gauges pushed from
 * the supervisor/master heartbeat) plus paused state at a glance.
 */
final class HorizonOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        try {
            $range = $this->metrics()->queryRange(
                'sum by (supervisor) ('.$this->metric('horizon_supervisor_processes').')',
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

    private function sumQuery(string $metric): string
    {
        return 'sum('.$this->metric($metric).')';
    }
}
