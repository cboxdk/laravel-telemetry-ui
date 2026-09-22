<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Artisan command runs (completed vs failed).
 */
final class CommandsOverview extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    public function data(): array
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

                $total = $this->total($metric->increase($p)->sumBy());
                $stats[] = $this->stat($label, Format::count($total), $total > 0 ? $tone : 'dim');

                $range = $this->metrics()->queryRange($metric->rate($w)->sumBy()->times(60), $start, $end);

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

    protected function statLinks(): array
    {
        return [
            'Completed' => Ui::explore('traces', ['laravel.command!=']),
            'Failed' => Ui::explore('traces', ['laravel.command!=', 'status=error']),
        ];
    }
}
