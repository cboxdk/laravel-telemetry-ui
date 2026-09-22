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
 * Most-viewed pages, with distinct visitors per page, from the
 * `analytics.page_view` stream. See {@see Analytics}.
 */
final class AnalyticsPages extends Panel
{
    private const SAMPLE_LIMIT = 5000;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $extra = ['subtitle' => 'Most-viewed pages, with distinct visitors each.'];

        try {
            $entries = $this->logs()->query(
                $this->logSelector()->pipe(Analytics::pageViewFilter()),
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            );

            $rows = Analytics::topBy(Analytics::rows($entries), 'path', 100);
        } catch (SourceException $exception) {
            return Ui::bars('Top pages', [], [...$extra, 'error' => $exception->getMessage()]);
        }

        // Each page drills into its own detail page — traffic, performance,
        // traces and errors scoped to the one URL path, not a trace search.
        $items = array_map(static fn (array $row): array => [
            'label' => $row['key'],
            'value' => $row['views'],
            'display' => Format::count($row['views']),
            'sub' => Format::count($row['visitors']).' '.Str::plural('visitor', $row['visitors']),
            'link' => Ui::entity('path', $row['key']),
        ], $rows);

        return Ui::bars('Top pages', $items, [
            ...$extra,
            'empty' => 'No page views in this period. Analytics runs under the app\'s own service — check the service scope.',
        ]);
    }
}
