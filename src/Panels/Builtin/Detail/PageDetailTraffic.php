<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Builtin\AnalyticsBreakdown;
use Cbox\TelemetryUi\Panels\Builtin\AnalyticsOverview;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;

/**
 * Traffic for a single page: the views-over-time trend plus where those
 * visits came from and who they are (referrers, countries, devices) — the
 * {@see AnalyticsOverview} +
 * {@see AnalyticsBreakdown} views, scoped to
 * this one `url.path`. See {@see Analytics}.
 */
final class PageDetailTraffic extends Panel
{
    use ScopesToPage;

    private const SAMPLE_LIMIT = 5000;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
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
                    $this->logSelector()->pipe(...$this->pageLogFilter())->pipe(Analytics::pageViewFilter()),
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

        $extra = ['subtitle' => 'Views over time, and where this page\'s visits come from.'];

        if ($error !== null) {
            return Ui::composite('Traffic', [], [...$extra, 'error' => $error]);
        }

        return Ui::composite('Traffic', [
            $this->chartCard(
                'Page views',
                series: $series,
                type: 'bar',
                height: 180,
                empty: 'No page views in this period.',
            ),
            self::bars('Referrers', $referrers, 'No referrer data yet.'),
            self::bars('Countries', $countries, 'Set TELEMETRY_ANALYTICS_GEO=true (+ a GeoLite2 db) in the emitter to see countries.'),
            self::bars('Devices', $devices, 'Set TELEMETRY_ANALYTICS_UA=true in the emitter to see devices.'),
        ], $extra);
    }

    /**
     * One top-N breakdown column: views per value.
     *
     * @param  list<array{key: string, views: int, visitors: int}>  $rows
     * @return array<string, mixed>
     */
    private static function bars(string $title, array $rows, string $hint): array
    {
        return Ui::bars(
            $title,
            array_map(static fn (array $row): array => [
                'label' => $row['key'],
                'value' => $row['views'],
                'display' => Format::count($row['views']),
            ], $rows),
            ['empty' => $hint],
        );
    }
}
