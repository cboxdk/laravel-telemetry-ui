<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of a page-detail page: the concrete URL path, a link back to
 * Analytics, and its headline numbers (views, unique visitors, avg browser
 * load, errors) over the period — visits from the `analytics.page_view` stream,
 * timings/errors from the browser RUM spans that share the trace.
 */
final class PageDetailHeader extends Card
{
    use ScopesToPage;

    private const SAMPLE_LIMIT = 5000;

    private const SEARCH_LIMIT = 200;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $error = null;
        $views = $visitors = 0;
        $avgLoad = null;
        $errors = 0;

        if ($this->page !== '') {
            try {
                $rows = Analytics::rows($this->logs()->query(
                    $this->logSelector().$this->pageLogFilter().Analytics::PAGE_VIEW_FILTER,
                    $start,
                    $end,
                    limit: self::SAMPLE_LIMIT,
                ));

                $views = count($rows);
                $visitors = Analytics::uniqueVisitors($rows);

                // Avg browser load (ms): the document.load span carries the
                // navigation timings, its own duration is the total load. Browser
                // spans key on `http.url`, not the backend-only `url.path`, so
                // scope by service/env and match the path in PHP.
                $loads = $this->traces()->search(
                    '{ '.$this->traceScope('span.browser.ttfb_ms != nil').' } | select(span.http.url)',
                    $start,
                    $end,
                    limit: self::SEARCH_LIMIT,
                );

                $sumLoad = 0.0;
                $count = 0;

                foreach ($loads as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        if (! $this->matchesPage($span->attributes['http.url'] ?? null)) {
                            continue;
                        }

                        $sumLoad += $span->durationMs;
                        $count++;
                    }
                }

                $avgLoad = $count > 0 ? $sumLoad / $count : null;

                // Errors: browser exception spans stamped with this page's URL.
                $errorResults = $this->traces()->search(
                    '{ '.$this->traceScope('span.browser = true && span.exception.type != nil').' } | select(span.http.url)',
                    $start,
                    $end,
                    limit: self::SEARCH_LIMIT,
                );

                foreach ($errorResults as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        if ($this->matchesPage($span->attributes['http.url'] ?? null)) {
                            $errors++;
                        }
                    }
                }
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->page === '' ? '(no page)' : $this->page,
            'subtitle' => 'Page detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← Analytics',
            'error' => $error,
            'stats' => [
                ['label' => 'Views', 'value' => Format::count($views), 'tone' => null],
                ['label' => 'Unique visitors', 'value' => Format::count($visitors), 'tone' => 'dim'],
                ['label' => 'Avg load', 'value' => $avgLoad !== null ? Format::ms($avgLoad) : '—', 'tone' => 'dim'],
                ['label' => 'Errors', 'value' => Format::count($errors), 'tone' => $errors > 0 ? 'danger' : 'dim'],
            ],
        ]);
    }
}
