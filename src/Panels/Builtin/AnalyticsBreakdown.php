<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Str;

/**
 * Where visitors came from and who they are: top referrers, sources, countries,
 * regions, cities, devices, operating systems and browsers, from the
 * `analytics.page_view` stream. Which columns show is `telemetry-ui.analytics.
 * dimensions`; each also needs its emitter capture flag (geo / user_agent) to
 * carry data, and the high-cardinality ones (city especially) are a Loki
 * stream-label cost paid at ingest — see the cardinality guide in
 * docs/cookbook/analytics.md and {@see Analytics}.
 */
final class AnalyticsBreakdown extends Panel
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

    /**
     * One dimension on its own (a DIMENSIONS key); empty = every enabled one.
     */
    #[Param('dimension')]
    public string $dimension = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        /** @var array<string, bool> $enabled */
        $enabled = (array) config('telemetry-ui.analytics.dimensions', []);

        $available = array_filter(
            self::DIMENSIONS,
            static fn (string $key): bool => ($enabled[$key] ?? true) !== false,
            ARRAY_FILTER_USE_KEY,
        );

        $selected = isset($available[$this->dimension]) ? [$this->dimension => $available[$this->dimension]] : $available;

        $extra = [
            'subtitle' => 'Where visits come from and who they are. Countries need the emitter\'s geo lookup; devices need its User-Agent parsing.',
            'controls' => $available === [] ? [] : [Ui::select(
                'dimension',
                'Dimension',
                isset($available[$this->dimension]) ? $this->dimension : '',
                [
                    ['value' => '', 'label' => 'All'],
                    ...array_map(
                        static fn (string $key, array $dimension): array => ['value' => $key, 'label' => $dimension[1]],
                        array_keys($available),
                        array_values($available),
                    ),
                ],
            )],
        ];

        if ($available === []) {
            return Ui::composite('Sources & audience', [], [
                ...$extra,
                'empty' => 'No analytics dimensions enabled — see telemetry-ui.analytics.dimensions.',
            ]);
        }

        try {
            $rows = Analytics::rows($this->logs()->query(
                $this->logSelector()->pipe(Analytics::pageViewFilter()),
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            ));
        } catch (SourceException $exception) {
            return Ui::composite('Sources & audience', [], [...$extra, 'error' => $exception->getMessage()]);
        }

        $parts = [];

        foreach ($selected as [$field, $title, $limit, $blank, $hint]) {
            $parts[] = Ui::bars(
                $title,
                self::barItems(Analytics::topBy($rows, $field, $limit, blank: $blank)),
                ['empty' => $hint],
            );
        }

        return Ui::composite('Sources & audience', $parts, $extra);
    }

    /**
     * Analytics top-N rows as bars: views as the bar, distinct visitors as the
     * sub-line.
     *
     * @param  list<array{key: string, views: int, visitors: int}>  $rows
     * @return list<array{label: string, value: int, display: string, sub: string}>
     */
    private static function barItems(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'label' => $row['key'],
            'value' => $row['views'],
            'display' => Format::count($row['views']),
            'sub' => Format::count($row['visitors']).' '.Str::plural('visitor', $row['visitors']),
        ], $rows);
    }
}
