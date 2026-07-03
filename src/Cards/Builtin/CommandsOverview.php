<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Artisan command runs (completed vs failed).
 */
final class CommandsOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $p = $this->promDuration();
        $w = $this->rateWindow();

        $series = [];
        $stats = [];

        try {
            foreach ([
                'completed' => ['Completed', '#34d399', 'ok'],
                'failed' => ['Failed', '#f87171', 'danger'],
            ] as $outcome => [$label, $color, $tone]) {
                $metric = $this->metric('commands_'.$outcome.'_total');

                $total = $this->total('sum(increase('.$metric.'['.$p.']))');
                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange('sum(rate('.$metric.'['.$w.'])) * 60', $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Commands', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Commands',
            subtitle: 'Artisan command runs per minute (completed vs failed)',
            series: $series,
            stats: $stats,
            type: 'bar',
            unit: 'runs/min',
            span: 2,
            note: 'Command instrumentation is opt-in: TELEMETRY_INSTRUMENT_COMMANDS=true.',
        );
    }
}
