<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * The concrete URLs behind a route pattern — `/{segments?}` is really
 * `/pricing`, `/blog/...`, and whatever bots probe — aggregated from the
 * route's own request spans: volume, latency, error mix per path. A row
 * opens the request log filtered to that path (live-tailable); the trace
 * link opens the newest example's story.
 */
final class RequestDetailPaths extends Card
{
    use ScopesToRoute;

    public function render(): View
    {
        $rows = [];
        $error = null;

        if ($this->route !== '') {
            [$start, $end] = $this->range();

            try {
                // Aggregate from the route's spans — NOT tagValues(), whose
                // filter Tempo quietly ignores on v1, leaking every path in
                // the backend into this card.
                $traceql = '{ '.$this->traceScope($this->routeTraceScope())
                    .' } | select(span.url.path, span.http.response.status_code)';

                $results = $this->traces()->search($traceql, $start, $end, limit: 100);

                /** @var array<string, array{path: string, count: int, sumMs: float, maxMs: float, errors: int, lastNano: int, traceId: string}> $paths */
                $paths = [];

                foreach ($results as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        $path = is_string($span->attributes['url.path'] ?? null) ? $span->attributes['url.path'] : '';

                        if ($path === '') {
                            continue;
                        }

                        $status = (int) ($span->attributes['http.response.status_code'] ?? 0);

                        $row = $paths[$path] ?? ['path' => $path, 'count' => 0, 'sumMs' => 0.0, 'maxMs' => 0.0, 'errors' => 0, 'lastNano' => 0, 'traceId' => ''];
                        $row['count']++;
                        $row['sumMs'] += $span->durationMs;
                        $row['maxMs'] = max($row['maxMs'], $span->durationMs);
                        $row['errors'] += $status >= 400 ? 1 : 0;

                        if ($span->startNano >= $row['lastNano']) {
                            $row['lastNano'] = $span->startNano;
                            $row['traceId'] = $summary->traceId;
                        }

                        $paths[$path] = $row;
                    }
                }

                $rows = array_values($paths);
                usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-paths';

        return view($view, ['rows' => array_slice($rows, 0, 50), 'error' => $error]);
    }

    /**
     * The request log filtered to this exact path — live-tailable.
     */
    public function logUrl(string $path): string
    {
        return $this->pageUrl('requests', ['req_view' => 'log', 'log_path' => $path]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
