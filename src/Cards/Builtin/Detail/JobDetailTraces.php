<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * The recent runs of a single job — its traces, on the job detail page.
 */
final class JobDetailTraces extends Card
{
    use ScopesToJob;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->job !== '') {
            try {
                $results = $this->traces()->search(
                    $this->traceQuery(...$this->jobTraceConditions()),
                    $start,
                    $end,
                    limit: 25,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-traces';

        return view($view, [
            'results' => $results,
            'error' => $error,
            'title' => 'Recent runs',
            'subtitle' => 'Traces for this job — click a row for the waterfall + host context',
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.page', array_filter(['page' => 'traces', 'trace' => $traceId]));
    }
}
