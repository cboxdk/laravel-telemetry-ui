<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Str;

/**
 * Campaign attribution from UTM tags on the landing URL — top campaigns,
 * sources, mediums (and, higher-cardinality, content/term) with distinct
 * visitors each. Needs the emitter's `telemetry.analytics.utm` capture on
 * (cboxdk/laravel-telemetry ≥ 0.3.0); until then it shows one empty state
 * rather than a wall of blank columns. Which columns show is
 * `telemetry-ui.analytics.dimensions`. See {@see Analytics}.
 */
final class AnalyticsCampaigns extends Panel
{
    private const SAMPLE_LIMIT = 5000;

    /**
     * Config key → [row field, title, top-N limit], in display order.
     *
     * @var array<string, array{string, string, int}>
     */
    private const DIMENSIONS = [
        'campaigns' => ['utm_campaign', 'Campaigns', 12],
        'utm_sources' => ['utm_source', 'Sources', 10],
        'utm_mediums' => ['utm_medium', 'Mediums', 10],
        'utm_contents' => ['utm_content', 'Content', 10],
        'utm_terms' => ['utm_term', 'Terms', 10],
    ];

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $extra = ['subtitle' => 'UTM campaign attribution from the landing URL — top campaigns, sources and mediums, with distinct visitors each.'];

        /** @var array<string, bool> $enabled */
        $enabled = (array) config('telemetry-ui.analytics.dimensions', []);

        try {
            $rows = Analytics::rows($this->logs()->query(
                $this->logSelector()->pipe(Analytics::pageViewFilter()),
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            ));
        } catch (SourceException $exception) {
            return Ui::composite('Campaigns', [], [...$extra, 'error' => $exception->getMessage()]);
        }

        $parts = [];

        foreach (self::DIMENSIONS as $key => [$field, $title, $limit]) {
            if (($enabled[$key] ?? true) === false) {
                continue;
            }

            $top = Analytics::topBy($rows, $field, $limit);

            // Only dimensions that carry data get a column (v1 parity).
            if ($top === []) {
                continue;
            }

            $parts[] = Ui::bars($title, array_map(static fn (array $row): array => [
                'label' => $row['key'],
                'value' => $row['views'],
                'display' => Format::count($row['views']),
                'sub' => Format::count($row['visitors']).' '.Str::plural('visitor', $row['visitors']),
            ], $top));
        }

        if ($parts === []) {
            $extra['empty'] = 'No campaign traffic in this period. Set TELEMETRY_ANALYTICS_UTM=true in the emitter '
                .'(cboxdk/laravel-telemetry ≥ 0.3.0) to capture utm_* tags and paid-click sources.';
        }

        return Ui::composite('Campaigns', $parts, $extra);
    }
}
