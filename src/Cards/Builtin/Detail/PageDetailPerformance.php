<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\FrontendPages;
use Cbox\TelemetryUi\Cards\Builtin\WebVitals;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Real-user performance for a single page: p75 Core Web Vitals (LCP/CLS/INP)
 * from the `web-vitals` spans, and the average navigation timings (load, TTFB,
 * DOM-interactive) from the `document.load` spans — the
 * {@see WebVitals} +
 * {@see FrontendPages} query logic scoped to
 * this one `url.path`. Field data, not lab; a bounded trace sample.
 */
final class PageDetailPerformance extends Card
{
    use CoercesAttributes;
    use ScopesToPage;

    private const SEARCH_LIMIT = 200;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $vitals = [];
        $timings = [];
        $error = null;

        if ($this->page !== '') {
            try {
                // Web vitals — one span per view, reported at page-hide.
                $lcp = $cls = $inp = [];

                // Browser spans carry the page in `http.url`, not `url.path`
                // (that's only on the backend span), so scope by service/env and
                // filter to this path in PHP — like WebVitals/FrontendPages.
                $vitalsResults = $this->traces()->search(
                    '{ '.$this->traceScope('name = "web-vitals"')
                        .' } | select(span.http.url, span.web_vitals.lcp_ms, span.web_vitals.cls, span.web_vitals.inp_ms)',
                    $start,
                    $end,
                    limit: self::SEARCH_LIMIT,
                );

                foreach ($vitalsResults as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        if ($span->name !== 'web-vitals' || ! $this->matchesPage($span->attributes['http.url'] ?? null)) {
                            continue;
                        }

                        if (isset($span->attributes['web_vitals.lcp_ms'])) {
                            $lcp[] = $this->num($span->attributes['web_vitals.lcp_ms']);
                        }
                        if (isset($span->attributes['web_vitals.cls'])) {
                            $cls[] = $this->num($span->attributes['web_vitals.cls']);
                        }
                        if (isset($span->attributes['web_vitals.inp_ms'])) {
                            $inp[] = $this->num($span->attributes['web_vitals.inp_ms']);
                        }
                    }
                }

                if ($lcp !== [] || $cls !== [] || $inp !== []) {
                    $vitals = [
                        $this->stat('p75 LCP', $this->fmt($this->p75($lcp), 'ms'), $this->tone($this->p75($lcp), 2500, 4000)),
                        $this->stat('p75 CLS', $this->fmt($this->p75($cls), ''), $this->tone($this->p75($cls), 0.1, 0.25)),
                        $this->stat('p75 INP', $this->fmt($this->p75($inp), 'ms'), $this->tone($this->p75($inp), 200, 500)),
                    ];
                }

                // Navigation timings — the document.load span carries them.
                $loadResults = $this->traces()->search(
                    '{ '.$this->traceScope('span.browser.ttfb_ms != nil')
                        .' } | select(span.http.url, span.browser.ttfb_ms, span.browser.dom_interactive_ms)',
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.page-detail-performance';

        return view($view, [
            'vitals' => $vitals,
            'timings' => $timings,
            'error' => $error,
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
}
