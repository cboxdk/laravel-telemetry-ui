<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Illuminate\Contracts\View\View;

/**
 * Where visitors came from and who they are: top referrers, countries and
 * devices, from the `analytics.page_view` stream. Referrers need no config;
 * country needs the emitter's geo lookup and device its User-Agent parsing, so
 * those sections stay empty until those are enabled. See {@see Analytics}.
 */
final class AnalyticsBreakdown extends Card
{
    private const SAMPLE_LIMIT = 5000;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $referrers = [];
        $countries = [];
        $devices = [];
        $error = null;

        try {
            $entries = $this->logs()->query(
                $this->logSelector().Analytics::PAGE_VIEW_FILTER,
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            );

            $rows = Analytics::rows($entries);
            $referrers = Analytics::topBy($rows, 'referrer', 10, blank: 'Direct / none');
            $countries = Analytics::topBy($rows, 'country', 10);
            $devices = Analytics::topBy($rows, 'device', 6);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.analytics-breakdown';

        return view($view, [
            'referrers' => $referrers,
            'countries' => $countries,
            'devices' => $devices,
            'error' => $error,
        ]);
    }
}
