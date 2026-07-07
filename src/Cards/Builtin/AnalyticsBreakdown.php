<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Illuminate\Contracts\View\View;

/**
 * Where visitors came from and who they are: top referrers, sources, countries,
 * regions, cities, devices, operating systems and browsers, from the
 * `analytics.page_view` stream. Which columns show is `telemetry-ui.analytics.
 * dimensions`; each also needs its emitter capture flag (geo / user_agent) to
 * carry data, and the high-cardinality ones (city especially) are a Loki
 * stream-label cost paid at ingest — see the cardinality guide in
 * docs/cookbook/analytics.md and {@see Analytics}.
 */
final class AnalyticsBreakdown extends Card
{
    private const SAMPLE_LIMIT = 5000;

    /**
     * The available dimensions in display order: config key → [row field, title,
     * top-N limit, blank-bucket label, empty-state hint].
     *
     * @var array<string, array{string, string, int, string|null, string}>
     */
    private const DIMENSIONS = [
        'channels' => ['channel', 'Channels', 8, null, 'No visits in this period.'],
        'referrers' => ['referrer', 'Referrers', 10, 'Direct / none', 'No referrer data yet.'],
        'sources' => ['source', 'Sources', 8, null, 'No source data yet.'],
        'countries' => ['country', 'Countries', 10, null, 'Enable telemetry.analytics.geo in the emitter to see countries.'],
        'regions' => ['region', 'Regions', 10, null, 'Regions need geo with region granularity (Cloudflare Enterprise or a MaxMind city db).'],
        'cities' => ['city', 'Cities', 10, null, 'Cities need geo with city granularity — and are high-cardinality; enable with care.'],
        'devices' => ['device', 'Devices', 6, null, 'Set TELEMETRY_ANALYTICS_UA=true in the emitter to see devices.'],
        'os' => ['os', 'Operating systems', 6, null, 'Set TELEMETRY_ANALYTICS_UA=true in the emitter to see operating systems.'],
        'browsers' => ['browser', 'Browsers', 8, null, 'Set TELEMETRY_ANALYTICS_UA=true in the emitter to see browsers.'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        /** @var array<int, array{title: string, rows: list<array{key: string, views: int, visitors: int}>, hint: string}> $sections */
        $sections = [];
        $error = null;

        /** @var array<string, bool> $enabled */
        $enabled = (array) config('telemetry-ui.analytics.dimensions', []);

        try {
            $rows = Analytics::rows($this->logs()->query(
                $this->logSelector()->pipe(Analytics::pageViewFilter()),
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            ));

            foreach (self::DIMENSIONS as $key => [$field, $title, $limit, $blank, $hint]) {
                if (($enabled[$key] ?? true) === false) {
                    continue;
                }

                $sections[] = [
                    'title' => $title,
                    'rows' => Analytics::topBy($rows, $field, $limit, blank: $blank),
                    'hint' => $hint,
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.analytics-breakdown';

        return view($view, [
            'sections' => $sections,
            'error' => $error,
        ]);
    }
}
