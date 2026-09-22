<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use DateTimeImmutable;

/**
 * Field Web Vitals from BOTH browser SDKs that feed Tempo:
 *
 * - laravel-telemetry's zero-build `@telemetryBrowser` script: one
 *   `web-vitals` span per page-hide carrying `web_vitals.lcp_ms`,
 *   `web_vitals.cls` and `web_vitals.inp_ms`, keyed by `http.url`;
 * - `@cboxdk/telemetry-browser`: one `browser.web_vital` marker span per
 *   metric (`web_vital.name` = LCP / CLS / INP / FCP / TTFB,
 *   `web_vital.value`, `web_vital.rating`), keyed by `url.path`.
 *
 * Both fold into per-path sample lists, so the panels render either.
 *
 * @phpstan-type VitalPage array{path: string, views: int, lcp: list<float>, cls: list<float>, inp: list<float>, fcp: list<float>, ttfb: list<float>}
 */
trait ReadsWebVitals
{
    /** Samples per search — each is one page (legacy) or one metric (marker). */
    private int $vitalsLimit = 500;

    /**
     * @param  callable(string): bool|null  $pathFilter  keep only matching paths
     * @return array<string, VitalPage>
     */
    protected function webVitalsByPath(DateTimeImmutable $start, DateTimeImmutable $end, ?callable $pathFilter = null): array
    {
        /** @var array<string, VitalPage> $pages */
        $pages = [];
        /** @var array<string, array<string, true>> $traces path → trace ids (marker schema views) */
        $traces = [];

        $legacy = $this->traceQuery(TraceCondition::eq('name', 'web-vitals'))
            ->select('span.http.url', 'span.web_vitals.lcp_ms', 'span.web_vitals.cls', 'span.web_vitals.inp_ms');

        foreach ($this->traces()->search($legacy, $start, $end, $this->vitalsLimit) as $summary) {
            foreach ($summary->matchedSpans as $span) {
                if ($span->name !== 'web-vitals') {
                    continue;
                }

                $path = $this->vitalPath($span);

                if ($pathFilter !== null && ! $pathFilter($path)) {
                    continue;
                }

                $page = $pages[$path] ?? self::emptyVitalPage($path);
                $page['views']++;

                foreach (['lcp' => 'web_vitals.lcp_ms', 'cls' => 'web_vitals.cls', 'inp' => 'web_vitals.inp_ms'] as $key => $attribute) {
                    if (isset($span->attributes[$attribute]) && is_numeric($span->attributes[$attribute])) {
                        $page[$key][] = (float) $span->attributes[$attribute];
                    }
                }

                $pages[$path] = $page;
            }
        }

        $markers = $this->traceQuery(TraceCondition::eq('name', 'browser.web_vital'))
            ->select('span.url.path', 'span.web_vital.name', 'span.web_vital.value');

        foreach ($this->traces()->search($markers, $start, $end, $this->vitalsLimit) as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $metric = strtolower((string) ($span->attributes['web_vital.name'] ?? ''));
                $value = $span->attributes['web_vital.value'] ?? null;

                if (! in_array($metric, ['lcp', 'cls', 'inp', 'fcp', 'ttfb'], true) || ! is_numeric($value)) {
                    continue;
                }

                $path = $this->vitalPath($span);

                if ($pathFilter !== null && ! $pathFilter($path)) {
                    continue;
                }

                $page = $pages[$path] ?? self::emptyVitalPage($path);
                $page[$metric][] = (float) $value;
                $traces[$path][$summary->traceId] = true;
                $page['views'] = max($page['views'], count($traces[$path]));
                $pages[$path] = $page;
            }
        }

        return $pages;
    }

    /**
     * The 75th percentile (the Core Web Vitals reporting convention).
     *
     * @param  list<float>  $values
     */
    protected function p75(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[(int) max(0, ceil(0.75 * count($values)) - 1)];
    }

    private function vitalPath(MatchedSpan $span): string
    {
        $path = $span->attributes['url.path'] ?? null;

        if (is_string($path) && $path !== '') {
            return $path;
        }

        $url = $span->attributes['http.url'] ?? null;
        $parsed = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($parsed) && $parsed !== '' ? $parsed : '/';
    }

    /**
     * @return VitalPage
     */
    private static function emptyVitalPage(string $path): array
    {
        return ['path' => $path, 'views' => 0, 'lcp' => [], 'cls' => [], 'inp' => [], 'fcp' => [], 'ttfb' => []];
    }
}
