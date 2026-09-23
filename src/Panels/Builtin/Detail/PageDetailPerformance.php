<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Builtin\FrontendPages;
use Cbox\TelemetryUi\Panels\Builtin\WebVitals;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Concerns\ReadsWebVitals;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\Format;

/**
 * Real-user performance for a single page: p75 Core Web Vitals (LCP/CLS/INP)
 * from the `web-vitals` spans, and the average navigation timings (load, TTFB,
 * DOM-interactive) from the `document.load` spans — the
 * {@see WebVitals} +
 * {@see FrontendPages} query logic scoped to
 * this one `url.path`. Field data, not lab; a bounded trace sample.
 */
final class PageDetailPerformance extends Panel
{
    use CoercesAttributes;
    use ReadsWebVitals;
    use ScopesToPage;

    private const SEARCH_LIMIT = 200;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $vitals = [];
        $timings = [];
        $error = null;

        if ($this->page !== '') {
            try {
                // Web vitals from either browser SDK (see ReadsWebVitals),
                // filtered to this page's path.
                $page = $this->webVitalsByPath($start, $end, fn (string $path): bool => $this->matchesPage($path))[$this->page] ?? null;
                $lcp = $page['lcp'] ?? [];
                $cls = $page['cls'] ?? [];
                $inp = $page['inp'] ?? [];

                if ($lcp !== [] || $cls !== [] || $inp !== []) {
                    $vitals = [
                        $this->stat('p75 LCP', $this->fmt($this->p75($lcp), 'ms'), $this->tone($this->p75($lcp), 2500, 4000)),
                        $this->stat('p75 CLS', $this->fmt($this->p75($cls), ''), $this->tone($this->p75($cls), 0.1, 0.25)),
                        $this->stat('p75 INP', $this->fmt($this->p75($inp), 'ms'), $this->tone($this->p75($inp), 200, 500)),
                    ];
                }

                // Navigation timings — the document.load span carries them.
                $loadResults = $this->traces()->search(
                    $this->traceQuery(TraceCondition::nil('span.browser.ttfb_ms'))
                        ->select('span.http.url', 'span.browser.ttfb_ms', 'span.browser.dom_interactive_ms'),
                    $start,
                    $end,
                    limit: self::SEARCH_LIMIT,
                );

                $loads = 0;
                $sumLoad = $sumTtfb = $sumDom = 0.0;

                foreach ($loadResults as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        if (! $this->matchesPage($span->attributes['http.url'] ?? null)) {
                            continue;
                        }

                        $loads++;
                        $sumLoad += $span->durationMs;
                        $sumTtfb += $this->num($span->attributes['browser.ttfb_ms'] ?? null);
                        $sumDom += $this->num($span->attributes['browser.dom_interactive_ms'] ?? null);
                    }
                }

                if ($loads > 0) {
                    $timings = [
                        $this->stat('Page loads', Format::count($loads)),
                        $this->stat('Avg load', Format::ms($sumLoad / $loads)),
                        $this->stat('Avg TTFB', Format::ms($sumTtfb / $loads)),
                        $this->stat('Avg DOM interactive', Format::ms($sumDom / $loads)),
                    ];
                }
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $extra = ['subtitle' => 'Real-user Core Web Vitals (p75) and navigation timings for this page — field data, not lab.'];

        if ($error !== null) {
            return Ui::composite('Performance', [], [...$extra, 'error' => $error]);
        }

        if ($vitals === [] && $timings === []) {
            return Ui::composite('Performance', [], [
                ...$extra,
                'empty' => 'No browser performance data for this page in this period. Requires the frontend SDK (@telemetryBrowser).',
            ]);
        }

        $parts = [];

        if ($vitals !== []) {
            $parts[] = Ui::stats('Core Web Vitals (p75)', $vitals);
        }

        if ($timings !== []) {
            $parts[] = Ui::stats('Navigation timings', $timings);
        }

        return Ui::composite('Performance', $parts, [
            ...$extra,
            'note' => 'Vitals green / amber / red on Google\'s good / needs-improvement / poor thresholds. Bounded trace sample.',
        ]);
    }

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
