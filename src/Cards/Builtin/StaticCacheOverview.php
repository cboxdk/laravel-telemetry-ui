<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Static-cache outcomes (hit/miss/write/invalidate) per minute, from the
 * statamic.static_cache.operations counter emitted by
 * cboxdk/statamic-telemetry.
 */
final class StaticCacheOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = [];
        $error = null;

        try {
            $series = $this->toChartSeries($this->metrics()->queryRange(
                'sum by (operation) (rate(statamic_static_cache_operations_total[5m])) * 60',
                $start,
                $end,
            ), 'operation');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.static-cache-overview';

        return view($view, [
            'series' => $series,
            'error' => $error,
        ]);
    }
}
