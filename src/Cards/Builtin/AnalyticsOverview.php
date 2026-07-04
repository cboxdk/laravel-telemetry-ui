<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Visit analytics headline: page views, unique visitors (the cookieless daily
 * session hash) and views-per-visit, from the emitter's unsampled
 * `analytics.page_view` stream. See {@see Analytics}.
 */
final class AnalyticsOverview extends Card
{
    private const SAMPLE_LIMIT = 5000;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $stats = [];
        $error = null;

        try {
            $entries = $this->logs()->query(
                $this->logSelector().Analytics::PAGE_VIEW_FILTER,
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            );

            $rows = Analytics::rows($entries);
            $views = count($rows);
            $visitors = Analytics::uniqueVisitors($rows);
            $topPage = Analytics::topBy($rows, 'path', 1);
            $topCountry = Analytics::topBy($rows, 'country', 1);

            $stats = [
                $this->stat('Page views', Format::count($views)),
                $this->stat('Unique visitors', Format::count($visitors)),
                $this->stat('Views / visit', $visitors > 0 ? number_format($views / $visitors, 1) : '—'),
                $this->stat('Top page', $topPage[0]['key'] ?? '—'),
                $this->stat('Top country', $topCountry[0]['key'] ?? '—'),
            ];
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.analytics-overview';

        return view($view, [
            'stats' => $stats,
            'error' => $error,
        ]);
    }
}
