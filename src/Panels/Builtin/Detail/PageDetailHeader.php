<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a page-detail page: the concrete URL path, a link back to
 * Analytics, and its headline numbers (views, unique visitors, avg browser
 * load, errors) over the period — visits from the `analytics.page_view` stream,
 * timings/errors from the browser RUM spans that share the trace.
 */
final class PageDetailHeader extends Panel
{
    use ScopesToPage;

    private const SAMPLE_LIMIT = 5000;

    private const SEARCH_LIMIT = 200;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $error = null;
        $views = $visitors = 0;
        $avgLoad = null;
        $errors = 0;

        if ($this->page !== '') {
            try {
                $rows = Analytics::rows($this->logs()->query(
                    $this->logSelector()->pipe(...$this->pageLogFilter())->pipe(Analytics::pageViewFilter()),
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
                    $this->traceQuery(TraceCondition::nil('span.browser.ttfb_ms'))->select('span.http.url'),
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
                    $this->traceQuery(
                        TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                        TraceCondition::nil('span.exception.type'),
                    )->select('span.http.url'),
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

        return Ui::header(
            $this->page === '' ? '(no page)' : $this->page,
            'Page detail',
            [
                $this->stat('Views', Format::count($views)),
                $this->stat('Unique visitors', Format::count($visitors), 'dim'),
                $this->stat('Avg load', $avgLoad !== null ? Format::ms($avgLoad) : '—', 'dim'),
                $this->stat('Errors', Format::count($errors), $errors > 0 ? 'danger' : 'dim'),
            ],
            array_filter([
                'drill' => $this->backLink(),
                'error' => $error,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }
}
