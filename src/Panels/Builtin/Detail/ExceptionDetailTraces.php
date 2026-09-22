<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;

/**
 * Error traces in scope for an exception detail page — the requests that blew
 * up, drilling from the class down to individual failures.
 */
final class ExceptionDetailTraces extends Panel
{
    use ScopesToException;

    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        try {
            $results = $this->traces()->search(
                $this->traceQuery(TraceCondition::token('status', TraceOp::Eq, 'error')),
                $start,
                $end,
                limit: 25,
            );
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-traces';

        return view($view, [
            'results' => $results,
            'error' => $error,
            'title' => 'Recent error traces',
            'subtitle' => 'Failed requests in this scope — click a row for the waterfall + host context',
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.page', array_filter(['page' => 'traces', 'trace' => $traceId]));
    }
}
