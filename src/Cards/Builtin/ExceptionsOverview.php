<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Reported exceptions over time (exceptions_reported_total).
 */
final class ExceptionsOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('exceptions_reported_total');

        try {
            $total = $this->total('sum(increase('.$metric.'['.$this->promDuration().']))');

            $range = $this->metrics()->queryRange(
                'sum(rate('.$metric.'['.$this->rateWindow().'])) * 60',
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
