<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;

/**
 * Visit analytics headline: page views, unique visitors (the cookieless daily
 * session hash) and views-per-visit, from the emitter's unsampled
 * `analytics.page_view` stream. See {@see Analytics}.
 */
final class AnalyticsOverview extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    private const SAMPLE_LIMIT = 5000;

    public function data(): array
    {
        [$start, $end] = $this->range();

        $stats = [];
        $series = [];
        $error = null;

        try {
            $selector = $this->logSelector();

            $rows = Analytics::rows($this->logs()->query(
                $selector->pipe(Analytics::pageViewFilter()), $start, $end, limit: self::SAMPLE_LIMIT,
            ));

            $engagementMs = Analytics::avgEngagementMs($this->logs()->query(
                $selector->pipe(Analytics::engagementFilter()), $start, $end, limit: self::SAMPLE_LIMIT,
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

    protected function statLinks(): array
    {
        return [
            'Page views' => Ui::explore('logs', ['analytics_event=page_view']),
            'Unique visitors' => Ui::explore('logs', ['analytics_event=page_view'], ['groupBy' => 'session.id']),
            'Views / visit' => Ui::explore('logs', ['analytics_event=page_view'], ['groupBy' => 'session.id']),
        ];
    }
}
