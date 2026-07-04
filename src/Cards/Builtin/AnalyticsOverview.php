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
        $series = [];
        $error = null;

        try {
            $selector = $this->logSelector();

            $rows = Analytics::rows($this->logs()->query(
                $selector.Analytics::PAGE_VIEW_FILTER, $start, $end, limit: self::SAMPLE_LIMIT,
            ));

            $engagementMs = Analytics::avgEngagementMs($this->logs()->query(
                $selector.Analytics::ENGAGEMENT_FILTER, $start, $end, limit: self::SAMPLE_LIMIT,
            ));

            $views = count($rows);
            $visitors = Analytics::uniqueVisitors($rows);
            $bounce = Analytics::bounceRate($rows);

            $stats = [
                $this->stat('Page views', Format::count($views)),
                $this->stat('Unique visitors', Format::count($visitors)),
                $this->stat('Views / visit', $visitors > 0 ? number_format($views / $visitors, 1) : '—'),
                $this->stat('Bounce rate', $bounce !== null ? Format::percent($bounce) : '—'),
                $this->stat('Avg engagement', $engagementMs !== null ? Format::ms($engagementMs) : '—'),
            ];

            $series = [[
                'name' => 'Page views',
                'data' => array_map(
                    static fn (array $point): array => [(float) $point[0], (float) $point[1]],
                    Analytics::viewsSeries($rows, $start->getTimestamp() * 1000, $end->getTimestamp() * 1000),
                ),
            ]];
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return $this->chartCard(
            'Analytics',
            series: $series,
            stats: $stats,
            type: 'bar',
            error: $error,
            span: 2,
            subtitle: 'Real visits from the unsampled page-view stream. Unique visitors are the cookieless daily session hash — no cookies, no PII.',
        );
    }
}
