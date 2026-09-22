<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;

/**
 * The recent traces touching a single page — the browser page load and the
 * backend request behind it share one trace, so each row is the full
 * frontend → backend waterfall. The drill-down that replaces a pre-filtered
 * trace search, embedded on the page's detail page.
 */
final class PageDetailTraces extends Panel
{
    use ScopesToPage;

    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->page !== '') {
            try {
                $results = $this->traces()->search(
                    $this->traceQuery(...$this->pageTraceConditions()),
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
