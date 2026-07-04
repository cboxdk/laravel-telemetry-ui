<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Recent outgoing calls to a single upstream host — the client spans, on the
 * host detail page.
 */
final class OutgoingHostTraces extends Card
{
    use ScopesToHost;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->host !== '') {
            try {
                $results = $this->traces()->search(
                    '{ '.$this->traceScope($this->hostTraceScope()).' }',
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
            'title' => 'Recent calls',
            'subtitle' => 'Traces containing a call to this host — click a row for the waterfall + host context',
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.page', array_filter(['page' => 'traces', 'trace' => $traceId]));
    }
}
