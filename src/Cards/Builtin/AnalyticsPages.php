<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Illuminate\Contracts\View\View;

/**
 * Most-viewed pages, with distinct visitors per page, from the
 * `analytics.page_view` stream. See {@see Analytics}.
 */
final class AnalyticsPages extends Card
{
    private const SAMPLE_LIMIT = 5000;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $entries = $this->logs()->query(
                $this->logSelector()->pipe(Analytics::pageViewFilter()),
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            );

            $rows = Analytics::topBy(Analytics::rows($entries), 'path', 100);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.analytics-pages';

        return view($view, [
            'rows' => $rows,
            'error' => $error,
        ]);
    }

    /**
     * This page's own detail page — traffic, performance, traces and errors
     * scoped to the one URL path, not a pre-filtered trace search.
     */
    public function pageDetailUrl(string $path): string
    {
        return $this->pageUrl('page-detail', ['path' => $path]);
    }
}
