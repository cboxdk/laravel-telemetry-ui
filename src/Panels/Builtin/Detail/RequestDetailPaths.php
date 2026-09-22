<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The concrete URLs behind a route pattern — `/{segments?}` is really
 * `/pricing`, `/blog/...`, and whatever bots probe — aggregated from the
 * route's own request spans: volume, latency, error mix per path. A row
 * opens the request log filtered to that path (live-tailable); the trace
 * link opens the newest example's story.
 */
final class RequestDetailPaths extends Panel
{
    use ScopesToRoute;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $rows = [];
        $error = null;

        if ($this->route !== '') {
            [$start, $end] = $this->range();

            try {
                // Aggregate from the route's spans — NOT tagValues(), whose
                // filter Tempo quietly ignores on v1, leaking every path in
                // the backend into this card.
                $query = $this->traceQuery(...$this->routeTraceConditions())
                    ->select('span.url.path', 'span.http.response.status_code');

                $results = $this->traces()->search($query, $start, $end, limit: 100);

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

        $table = array_map(static fn (array $row): array => [
            'path' => Ui::cell($row['path'], ['mono' => true, 'dim' => ['key' => 'url.path', 'value' => $row['path']]]),
            'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count']]),
            'avg' => Ui::cell(Format::ms($row['sumMs'] / max(1, $row['count'])), ['raw' => $row['sumMs'] / max(1, $row['count']), 'tone' => 'dim']),
            'max' => Ui::cell(Format::ms($row['maxMs']), ['raw' => $row['maxMs'], 'tone' => $row['maxMs'] > 1000 ? 'warn' : 'dim']),
            'errors' => Ui::cell($row['errors'], ['raw' => $row['errors'], 'tone' => $row['errors'] > 0 ? 'danger' : 'dim']),
            'latest' => $row['traceId'] !== ''
                ? Ui::cell('⇄', ['link' => Ui::trace($row['traceId'])])
                : Ui::cell('—'),
            // The request log filtered to this exact path — live-tailable.
            '_link' => Ui::page('requests', ['log_path' => $row['path']]),
        ], array_slice($rows, 0, 50));

        return Ui::table('Paths', [
            Ui::col('path', 'Path'),
            Ui::num('count', 'Requests'),
            Ui::num('avg', 'Avg'),
            Ui::num('max', 'Max'),
            Ui::num('errors', '4xx/5xx'),
            Ui::num('latest', 'Latest'),
        ], $table, array_filter([
            'subtitle' => 'Concrete URLs behind this route pattern — click a row to tail that path in the request log',
            'error' => $error,
            'empty' => 'No sampled requests for this route in the period.',
            'note' => 'Aggregated from a bounded trace sample. Row → request log for the path; ⇄ → the newest request\'s full story.',
        ], static fn ($v): bool => $v !== null));
    }
}
