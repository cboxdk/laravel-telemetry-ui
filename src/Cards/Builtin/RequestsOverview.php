<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Requests per minute by service, from the http_server_request_duration
 * histogram emitted by cboxdk/laravel-telemetry.
 */
final class RequestsOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = [];
        $error = null;

        try {
            $series = $this->toChartSeries($this->metrics()->queryRange(
                'sum by (service_name) (rate(http_server_request_duration_milliseconds_count[5m])) * 60',
                $start,
                $end,
            ), 'service_name');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.requests-overview';

        return view($view, [
            'series' => $series,
            'error' => $error,
        ]);
    }
}
