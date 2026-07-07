<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Core Web Vitals from real users — the browser SDK ships one `web-vitals`
 * span per page view at page-hide (LCP/CLS are not final before that), so
 * these are field numbers, not lab scores. Grouped by URL path with
 * good / needs-improvement / poor tones on Google's published thresholds.
 */
final class WebVitals extends Card
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
            $traceql = '{ '.$this->traceScope('name = "web-vitals"')
                .' } | select(span.http.url, span.web_vitals.lcp_ms, span.web_vitals.cls, span.web_vitals.inp_ms)';

            $results = $this->traces()->search($traceql, $start, $end, limit: self::SEARCH_LIMIT);

            /** @var array<string, array{path: string, views: int, lcp: list<float>, cls: list<float>, inp: list<float>}> $pages */
            $pages = [];

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    if ($span->name !== 'web-vitals') {
                        continue;
                    }

                    $path = $this->path($span->attributes['http.url'] ?? null);
                    $page = $pages[$path] ?? ['path' => $path, 'views' => 0, 'lcp' => [], 'cls' => [], 'inp' => []];
                    $page['views']++;

                    foreach (['lcp' => 'web_vitals.lcp_ms', 'cls' => 'web_vitals.cls', 'inp' => 'web_vitals.inp_ms'] as $key => $attribute) {
                        if (isset($span->attributes[$attribute])) {
                            $page[$key][] = $this->num($span->attributes[$attribute]);
                        }
                    }

                    $pages[$path] = $page;
                }
            }

            $allLcp = [];
            $allCls = [];
            $allInp = [];

            foreach ($pages as $page) {
                $allLcp = [...$allLcp, ...$page['lcp']];
                $allCls = [...$allCls, ...$page['cls']];
                $allInp = [...$allInp, ...$page['inp']];

                $rows[] = [
                    'path' => $page['path'],
                    'views' => $page['views'],
                    'lcp' => $this->p75($page['lcp']),
                    'cls' => $this->p75($page['cls']),
                    'inp' => $this->p75($page['inp']),
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

            if ($rows !== []) {
                $stats = [
                    $this->stat('p75 LCP', $this->fmt($this->p75($allLcp), 'ms'), $this->tone($this->p75($allLcp), 2500, 4000)),
                    $this->stat('p75 CLS', $this->fmt($this->p75($allCls), ''), $this->tone($this->p75($allCls), 0.1, 0.25)),
                    $this->stat('p75 INP', $this->fmt($this->p75($allInp), 'ms'), $this->tone($this->p75($allInp), 200, 500)),
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.web-vitals';

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
     * Web-vitals tone on Google's good/poor thresholds.
     */
    public function tone(?float $value, float $good, float $poor): string
    {
        return match (true) {
            $value === null => 'dim',
            $value <= $good => 'ok',
            $value <= $poor => 'warn',
            default => 'danger',
        };
    }

    public function fmt(?float $value, string $unit): string
    {
        return match (true) {
            $value === null => '—',
            $unit === 'ms' => Format::ms($value),
            default => rtrim(rtrim(number_format($value, 3), '0'), '.'),
        };
    }

    /**
     * The 75th percentile — the vitals-standard aggregate (an average hides
     * the slow tail these scores exist to expose).
     *
     * @param  list<float>  $values
     */
    private function p75(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[(int) min(count($values) - 1, floor(count($values) * 0.75))];
    }

    private function path(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '(unknown)';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }
}
