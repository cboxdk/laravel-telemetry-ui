<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Reported exceptions over time (exceptions_reported_total).
 */
class ExceptionsOverview extends Panel
{
    protected ?string $drillPage = 'exceptions';

    public function data(): array
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('exceptions_reported_total');

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());

            $range = $this->metrics()->queryRange(
                $metric->rate($this->rateWindow())->sumBy()->times(60),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Exceptions', error: $exception->getMessage());
        }

        $series = isset($range[0])
            ? [['name' => 'Exceptions', 'data' => $range[0]->toChartData(), 'color' => '#f87171']]
            : [];

        return $this->chartCard(
            title: 'Exceptions',
            subtitle: 'Exceptions reported via report()/the exception handler, per minute',
            series: $series,
            stats: [
                $this->stat('Reported', Format::count($total), $total > 0 ? 'danger' : 'dim'),
            ],
            type: 'bar',
            unit: '/min',
        );
    }
}
