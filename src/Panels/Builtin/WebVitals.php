<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsWebVitals;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Core Web Vitals from real users (field numbers, not lab scores) — read from
 * either browser SDK, see {@see ReadsWebVitals}. Grouped by URL path with
 * good / needs-improvement / poor tones on Google's published thresholds.
 */
final class WebVitals extends Panel
{
    use ReadsWebVitals;

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
            $pages = $this->webVitalsByPath($start, $end);

            $all = ['lcp' => [], 'cls' => [], 'inp' => [], 'fcp' => [], 'ttfb' => []];

            foreach ($pages as $page) {
                foreach (array_keys($all) as $metric) {
                    $all[$metric] = [...$all[$metric], ...$page[$metric]];
                }

                $rows[] = [
                    'path' => $page['path'],
                    'views' => $page['views'],
                    'lcp' => $this->p75($page['lcp']),
                    'cls' => $this->p75($page['cls']),
                    'inp' => $this->p75($page['inp']),
                    'fcp' => $this->p75($page['fcp']),
                    'ttfb' => $this->p75($page['ttfb']),
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

            if ($rows !== []) {
                $stats = [
                    $this->stat('p75 LCP', $this->fmt($this->p75($all['lcp']), 'ms'), $this->tone($this->p75($all['lcp']), 2500, 4000)),
                    $this->stat('p75 CLS', $this->fmt($this->p75($all['cls']), ''), $this->tone($this->p75($all['cls']), 0.1, 0.25)),
                    $this->stat('p75 INP', $this->fmt($this->p75($all['inp']), 'ms'), $this->tone($this->p75($all['inp']), 200, 500)),
                ];

                if ($all['fcp'] !== [] || $all['ttfb'] !== []) {
                    $stats[] = $this->stat('p75 FCP', $this->fmt($this->p75($all['fcp']), 'ms'), $this->tone($this->p75($all['fcp']), 1800, 3000));
                    $stats[] = $this->stat('p75 TTFB', $this->fmt($this->p75($all['ttfb']), 'ms'), $this->tone($this->p75($all['ttfb']), 800, 1800));
                }
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
                'empty' => 'No web-vitals spans in this period. Requires a browser SDK reporting vitals: @telemetryBrowser (data-vitals) or @cboxdk/telemetry-browser.',
            ]);
        }

        $columns = [
            Ui::col('path', 'Page'),
            Ui::num('views', 'Views'),
            Ui::num('lcp', 'LCP p75'),
            Ui::num('cls', 'CLS p75'),
            Ui::num('inp', 'INP p75'),
            Ui::num('fcp', 'FCP p75'),
            Ui::num('ttfb', 'TTFB p75'),
        ];

        $cells = array_map(fn (array $row): array => [
            'path' => Ui::cell($row['path'], ['mono' => true, 'dim' => ['key' => 'url.path', 'value' => $row['path']]]),
            'views' => Ui::cell((string) $row['views'], ['raw' => $row['views']]),
            'lcp' => Ui::cell($this->fmt($row['lcp'], 'ms'), ['raw' => $row['lcp'], 'tone' => $this->tone($row['lcp'], 2500, 4000)]),
            'cls' => Ui::cell($this->fmt($row['cls'], ''), ['raw' => $row['cls'], 'tone' => $this->tone($row['cls'], 0.1, 0.25)]),
            'inp' => Ui::cell($this->fmt($row['inp'], 'ms'), ['raw' => $row['inp'], 'tone' => $this->tone($row['inp'], 200, 500)]),
            'fcp' => Ui::cell($this->fmt($row['fcp'], 'ms'), ['raw' => $row['fcp'], 'tone' => $this->tone($row['fcp'], 1800, 3000)]),
            'ttfb' => Ui::cell($this->fmt($row['ttfb'], 'ms'), ['raw' => $row['ttfb'], 'tone' => $this->tone($row['ttfb'], 800, 1800)]),
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
}
