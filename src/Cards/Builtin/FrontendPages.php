<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Real-user page performance, straight from the browser (RUM). The frontend
 * SDK records a `document.load` span per navigation carrying the navigation
 * timings — total load (the span's own duration), TTFB and DOM-interactive —
 * so this is what real visitors experienced, not a synthetic probe. Grouped by
 * URL path. Trace-sourced (no RUM metric exists), so it's a bounded sample.
 */
final class FrontendPages extends Card
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 200;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $stats = [];
        $error = null;

        try {
            // document.load spans are the ones carrying the navigation timings.
            $query = $this->traceQuery(TraceCondition::nil('span.browser.ttfb_ms'))
                ->select('span.http.url', 'span.browser.ttfb_ms', 'span.browser.dom_interactive_ms');

            $results = $this->traces()->search($query, $start, $end, limit: self::SEARCH_LIMIT);

            /** @var array<string, array{path: string, loads: int, loadMs: float, ttfb: float, dom: float}> $pages */
            $pages = [];
            $totalLoads = 0;
            $sumLoad = 0.0;
            $sumTtfb = 0.0;
            $sumDom = 0.0;

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $path = $this->path($span->attributes['http.url'] ?? null);
                    $ttfb = $this->num($span->attributes['browser.ttfb_ms'] ?? null);
                    $dom = $this->num($span->attributes['browser.dom_interactive_ms'] ?? null);

                    $page = $pages[$path] ?? ['path' => $path, 'loads' => 0, 'loadMs' => 0.0, 'ttfb' => 0.0, 'dom' => 0.0];
                    $page['loads']++;
                    $page['loadMs'] += $span->durationMs;
                    $page['ttfb'] += $ttfb;
                    $page['dom'] += $dom;
                    $pages[$path] = $page;

                    $totalLoads++;
                    $sumLoad += $span->durationMs;
                    $sumTtfb += $ttfb;
                    $sumDom += $dom;
                }
            }

            $rows = array_map(static fn (array $p): array => [
                'path' => $p['path'],
                'loads' => $p['loads'],
                'loadMs' => $p['loadMs'] / $p['loads'],
                'ttfb' => $p['ttfb'] / $p['loads'],
                'dom' => $p['dom'] / $p['loads'],
            ], array_values($pages));

            usort($rows, static fn (array $a, array $b): int => $b['loads'] <=> $a['loads']);

            if ($totalLoads > 0) {
                $stats = [
                    $this->stat('Page loads', Format::count($totalLoads)),
                    $this->stat('Avg load', Format::ms($sumLoad / $totalLoads)),
                    $this->stat('Avg TTFB', Format::ms($sumTtfb / $totalLoads)),
                    $this->stat('Avg DOM interactive', Format::ms($sumDom / $totalLoads)),
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.frontend-pages';

        return view($view, [
            'stats' => $stats,
            'rows' => array_slice($rows, 0, 100),
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

    /**
     * The path portion of a full URL, for grouping (drops origin + query).
     */
    private function path(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '(unknown)';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }
}
