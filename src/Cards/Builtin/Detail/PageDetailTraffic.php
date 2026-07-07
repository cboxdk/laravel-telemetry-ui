<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\AnalyticsBreakdown;
use Cbox\TelemetryUi\Cards\Builtin\AnalyticsOverview;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Illuminate\Contracts\View\View;

/**
 * Traffic for a single page: the views-over-time trend plus where those
 * visits came from and who they are (referrers, countries, devices) — the
 * {@see AnalyticsOverview} +
 * {@see AnalyticsBreakdown} views, scoped to
 * this one `url.path`. See {@see Analytics}.
 */
final class PageDetailTraffic extends Card
{
    use ScopesToPage;

    private const SAMPLE_LIMIT = 5000;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = [];
        $referrers = [];
        $countries = [];
        $devices = [];
        $error = null;

        if ($this->page !== '') {
            try {
                $rows = Analytics::rows($this->logs()->query(
                    $this->logSelector().$this->pageLogFilter().Analytics::PAGE_VIEW_FILTER,
                    $start,
                    $end,
                    limit: self::SAMPLE_LIMIT,
                ));

                $series = [[
                    'name' => 'Page views',
                    'data' => array_map(
                        static fn (array $point): array => [(float) $point[0], (float) $point[1]],
                        Analytics::viewsSeries($rows, $start->getTimestamp() * 1000, $end->getTimestamp() * 1000),
                    ),
                ]];

                $referrers = Analytics::topBy($rows, 'referrer', 10, blank: 'Direct / none');
                $countries = Analytics::topBy($rows, 'country', 10);
                $devices = Analytics::topBy($rows, 'device', 6);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.page-detail-traffic';

        return view($view, [
            'series' => $series,
            'referrers' => $referrers,
            'countries' => $countries,
            'devices' => $devices,
            'error' => $error,
            'min' => $start->getTimestamp() * 1000,
            'max' => $end->getTimestamp() * 1000,
        ]);
    }
}
