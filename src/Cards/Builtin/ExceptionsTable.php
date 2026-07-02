<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Reported exceptions by class, with a link to matching error traces.
 */
final class ExceptionsTable extends Card
{
    public function render(): View
    {
        $rows = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                'sum by (exception) (increase('.$this->metric('exceptions_reported_total').'['.$this->promDuration().']))',
            );

            foreach ($samples as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $rows[] = [
                    'exception' => $sample->labels['exception'] ?? '?',
                    'count' => $sample->value,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.exceptions-table';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'errorTracesUrl' => $this->errorTracesUrl(),
        ]);
    }

    private function errorTracesUrl(): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('status = error').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
