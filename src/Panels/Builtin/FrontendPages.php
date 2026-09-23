<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\Format;

/**
 * Real-user page performance, straight from the browser (RUM). The frontend
 * SDK records a `document.load` span per navigation carrying the navigation
 * timings — total load (the span's own duration), TTFB and DOM-interactive —
 * so this is what real visitors experienced, not a synthetic probe. Grouped by
 * URL path. Trace-sourced (no RUM metric exists), so it's a bounded sample.
 */
final class FrontendPages extends Panel
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 200;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
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

        $extra = ['subtitle' => 'Real-user navigation timings from the browser (RUM), grouped by page.'];

        if ($error !== null) {
            return Ui::composite('Page performance', [], [...$extra, 'error' => $error]);
        }

        if ($rows === []) {
            return Ui::composite('Page performance', [], [
                ...$extra,
                'empty' => 'No browser page loads in this period. RUM runs under the app\'s own service — check the service scope, and that the frontend SDK (@telemetryBrowser) is enabled.',
            ]);
        }

        $columns = [
            Ui::col('path', 'Page'),
            Ui::num('loads', 'Loads'),
            Ui::num('loadMs', 'Avg load'),
            Ui::num('ttfb', 'TTFB'),
            Ui::num('dom', 'DOM interactive'),
        ];

        // Each row drills into the page's own detail page, not a trace search.
        $cells = array_map(static fn (array $row): array => [
            'path' => Ui::cell($row['path'], ['mono' => true, 'dim' => ['key' => 'url.path', 'value' => $row['path']]]),
            'loads' => Ui::cell(Format::count($row['loads']), ['raw' => $row['loads']]),
            'loadMs' => Ui::cell(Format::ms($row['loadMs']), ['raw' => $row['loadMs']]),
            'ttfb' => Ui::cell(Format::ms($row['ttfb']), ['raw' => $row['ttfb'], 'tone' => 'dim']),
            'dom' => Ui::cell(Format::ms($row['dom']), ['raw' => $row['dom'], 'tone' => 'dim']),
            '_link' => Ui::entity('path', $row['path']),
        ], array_slice($rows, 0, 100));

        return Ui::composite('Page performance', [
            Ui::stats('', $stats),
            Ui::table('', $columns, $cells),
        ], $extra);
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
