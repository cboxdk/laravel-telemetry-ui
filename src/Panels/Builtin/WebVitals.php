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
 * Core Web Vitals from real users — the browser SDK ships one `web-vitals`
 * span per page view at page-hide (LCP/CLS are not final before that), so
 * these are field numbers, not lab scores. Grouped by URL path with
 * good / needs-improvement / poor tones on Google's published thresholds.
 */
final class WebVitals extends Panel
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
            $query = $this->traceQuery(TraceCondition::eq('name', 'web-vitals'))
                ->select('span.http.url', 'span.web_vitals.lcp_ms', 'span.web_vitals.cls', 'span.web_vitals.inp_ms');

            $results = $this->traces()->search($query, $start, $end, limit: self::SEARCH_LIMIT);

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

        $extra = ['subtitle' => 'Real-user p75 LCP / CLS / INP per page, reported at page-hide (field data, not lab)'];

        if ($error !== null) {
            return Ui::composite('Core Web Vitals', [], [...$extra, 'error' => $error]);
        }

        if ($rows === []) {
            return Ui::composite('Core Web Vitals', [], [
                ...$extra,
                'empty' => 'No web-vitals spans in this period. Requires the browser SDK with ingest.spans.browser.vitals enabled (default on).',
            ]);
        }

        $columns = [
            Ui::col('path', 'Page'),
            Ui::num('views', 'Views'),
            Ui::num('lcp', 'LCP p75'),
            Ui::num('cls', 'CLS p75'),
            Ui::num('inp', 'INP p75'),
        ];

        $cells = array_map(fn (array $row): array => [
            'path' => Ui::cell($row['path'], ['mono' => true, 'dim' => ['key' => 'url.path', 'value' => $row['path']]]),
            'views' => Ui::cell((string) $row['views'], ['raw' => $row['views']]),
            'lcp' => Ui::cell($this->fmt($row['lcp'], 'ms'), ['raw' => $row['lcp'], 'tone' => $this->tone($row['lcp'], 2500, 4000)]),
            'cls' => Ui::cell($this->fmt($row['cls'], ''), ['raw' => $row['cls'], 'tone' => $this->tone($row['cls'], 0.1, 0.25)]),
            'inp' => Ui::cell($this->fmt($row['inp'], 'ms'), ['raw' => $row['inp'], 'tone' => $this->tone($row['inp'], 200, 500)]),
            '_link' => Ui::entity('path', $row['path']),
        ], array_slice($rows, 0, 100));

        return Ui::composite('Core Web Vitals', [
            Ui::stats('', $stats),
            Ui::table('', $columns, $cells),
        ], [
            ...$extra,
            'note' => 'Green / amber / red on Google\'s good / needs-improvement / poor thresholds. Bounded trace sample.',
        ]);
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
