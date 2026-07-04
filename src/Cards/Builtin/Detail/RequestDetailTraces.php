<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * The recent traces for a single route — the drill-down that replaces a
 * pre-filtered trace search, embedded on the route's detail page.
 */
final class RequestDetailTraces extends Card
{
    use ScopesToRoute;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->route !== '') {
            try {
                $results = $this->traces()->search(
                    '{ '.$this->traceScope($this->routeTraceScope()).' }',
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

        return view($view, ['results' => $results, 'error' => $error]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'trace' => $traceId,
        ]));
    }
}
