<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * Browser fetch/XHR calls that failed (5xx or a network/CORS error), from the
 * frontend SDK — the API calls that broke for real users, grouped by URL. Each
 * row opens a representative trace, where a same-origin failure continues into
 * the backend span that produced it (traceparent), so a frontend symptom leads
 * straight to its server-side cause.
 */
final class FrontendFetches extends Card
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 200;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $traceql = '{ '.$this->traceScope('span.browser = true && name =~ "fetch.*" && status = error')
                .' } | select(span.http.url, span.http.response.status_code)';

            $results = $this->traces()->search($traceql, $start, $end, limit: self::SEARCH_LIMIT);

            /** @var array<string, array{url: string, status: string, count: int, lastNano: int, traceId: string}> $calls */
            $calls = [];

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $url = $this->url($span->attributes['http.url'] ?? null);

                    $call = $calls[$url] ?? ['url' => $url, 'status' => '', 'count' => 0, 'lastNano' => 0, 'traceId' => ''];
                    $call['count']++;

                    if ($span->startNano >= $call['lastNano']) {
                        $call['lastNano'] = $span->startNano;
                        $call['status'] = $this->str($span->attributes['http.response.status_code'] ?? null) ?? 'error';
                        $call['traceId'] = $summary->traceId;
                    }

                    $calls[$url] = $call;
                }
            }

            $rows = array_values($calls);
            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['lastNano'] <=> $a['lastNano']);

            $rows = array_map(static fn (array $row): array => [
                ...$row,
                'lastSeen' => Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(),
            ], $rows);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.frontend-fetches';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
        ]);
    }

    /**
     * The URL without its query string, for grouping (keeps the host so a
     * failing third-party API is distinguishable from a same-origin one).
     */
    private function url(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '(unknown)';
        }

        return strtok($url, '?') ?: $url;
    }
}
