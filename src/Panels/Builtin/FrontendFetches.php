<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Carbon;

/**
 * Browser fetch/XHR calls that failed (5xx or a network/CORS error), from the
 * frontend SDK — the API calls that broke for real users, grouped by URL. Each
 * row opens a representative trace, where a same-origin failure continues into
 * the backend span that produced it (traceparent), so a frontend symptom leads
 * straight to its server-side cause.
 */
final class FrontendFetches extends Panel
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
        $error = null;

        try {
            $query = $this->traceQuery(
                TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                TraceCondition::re('name', 'fetch.*'),
                TraceCondition::token('status', TraceOp::Eq, 'error'),
            )->select('span.http.url', 'span.http.response.status_code');

            $results = $this->traces()->search($query, $start, $end, limit: self::SEARCH_LIMIT);

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
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $extra = [
            'subtitle' => 'fetch/XHR calls that failed for real users (5xx or network error). Click a row for the trace.',
            'empty' => 'No failed browser requests in this period.',
            'error' => $error,
        ];

        $columns = [
            Ui::col('url', 'Request'),
            Ui::num('status', 'Status'),
            Ui::num('count', 'Count'),
            Ui::num('lastSeen', 'Last seen'),
        ];

        // Each row opens a representative trace — a same-origin failure
        // continues into the backend span behind it.
        $cells = array_map(static fn (array $row): array => [
            'url' => Ui::cell($row['url'], ['mono' => true]),
            'status' => Ui::cell($row['status'], ['tone' => 'danger', 'mono' => true]),
            'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count']]),
            'lastSeen' => Ui::cell(Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(), ['raw' => intdiv($row['lastNano'], 1_000_000), 'tone' => 'dim']),
            '_link' => Ui::trace($row['traceId']),
        ], array_slice($rows, 0, 100));

        return Ui::table('Failed browser requests', $columns, $cells, array_filter($extra, static fn (?string $v): bool => $v !== null));
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
