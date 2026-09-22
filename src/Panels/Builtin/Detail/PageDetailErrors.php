<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The errors seen on a single page — browser exception spans stamped with this
 * page's URL (matched on `http.url`, since `url.path` is only on the backend
 * span), grouped by the same read-side fingerprint the unified errors list uses
 * ({@see ExceptionFingerprint}). Backend records don't carry the concrete path,
 * so this is the frontend (RUM) slice; each row drills into that issue's own
 * page. Empty state when the page is clean.
 */
final class PageDetailErrors extends Panel
{
    use CoercesAttributes;
    use ScopesToPage;

    private const TRACE_SEARCH_LIMIT = 100;

    /** Sparkline resolution: buckets across the page period. */
    private const BUCKETS = 24;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $fromNano = $start->getTimestamp() * 1_000_000_000;
        $toNano = $end->getTimestamp() * 1_000_000_000;

        $rows = [];
        $error = null;
        $truncated = false;

        if ($this->page !== '') {
            try {
                /** @var array<string, array{group: string, type: string, message: string, count: int, lastNano: int, buckets: array<int, int>}> $groups */
                $groups = [];

                $query = $this->traceQuery(
                    TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                    TraceCondition::nil('span.exception.type'),
                )->select('span.http.url', 'span.exception.type', 'span.exception.message', 'span.exception.file', 'span.exception.line');

                $results = $this->traces()->search($query, $start, $end, limit: self::TRACE_SEARCH_LIMIT);

                $truncated = count($results) >= self::TRACE_SEARCH_LIMIT;

                foreach ($results as $summary) {
                    foreach ($summary->matchedSpans as $span) {
                        $type = $this->str($span->attributes['exception.type'] ?? null);

                        if ($type === null || ! $this->matchesPage($span->attributes['http.url'] ?? null)) {
                            continue;
                        }

                        $group = ExceptionFingerprint::compute(
                            $type,
                            $this->str($span->attributes['exception.file'] ?? null) ?? '',
                            (int) ($span->attributes['exception.line'] ?? 0),
                        );

                        $row = $groups[$group] ?? [
                            'group' => $group, 'type' => '', 'message' => '', 'count' => 0,
                            'lastNano' => 0, 'buckets' => array_fill(0, self::BUCKETS, 0),
                        ];

                        $row['count']++;

                        $windowNano = max(1, $toNano - $fromNano);
                        $bucket = min(self::BUCKETS - 1, (int) (($span->startNano - $fromNano) / $windowNano * self::BUCKETS));
                        $row['buckets'][$bucket]++;

                        if ($span->startNano >= $row['lastNano']) {
                            $row['lastNano'] = $span->startNano;
                            $row['type'] = $type;
                            $row['message'] = $this->str($span->attributes['exception.message'] ?? null) ?? '';
                        }

                        $groups[$group] = $row;
                    }
                }

                $rows = array_map(static fn (array $row): array => [
                    ...$row,
                    'lastSeen' => Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(),
                ], array_values($groups));

                usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['lastNano'] <=> $a['lastNano']);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $columns = [
            Ui::col('error', 'Error'),
            Ui::col('trend', 'Trend'),
            Ui::num('count', 'Events'),
            Ui::num('lastSeen', 'Last seen'),
        ];

        // Each row opens the issue — trend, tags, stacktrace, root cause.
        $cells = array_map(static fn (array $row): array => [
            'error' => Ui::cell($row['type'] !== '' ? $row['type'] : $row['group'], array_filter([
                'mono' => true,
                'sub' => $row['message'] !== '' ? Str::limit($row['message'], 120) : null,
            ], static fn (mixed $v): bool => $v !== null)),
            'trend' => Ui::cell(null, [
                'spark' => array_map(static fn (int $n): float => (float) $n, array_values($row['buckets'])),
                'tone' => 'danger',
            ]),
            'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count'], 'tone' => 'danger']),
            'lastSeen' => Ui::cell($row['lastSeen'], ['raw' => intdiv($row['lastNano'], 1_000_000), 'tone' => 'dim']),
            '_link' => Ui::error($row['group']),
        ], array_slice($rows, 0, 100));

        return Ui::table('Errors', $columns, $cells, array_filter([
            'subtitle' => 'Browser errors seen on this page, grouped by fingerprint. Click a row for the issue\'s stacktrace and root cause.',
            'empty' => 'No errors on this page in this period. 🎉',
            'note' => $truncated && $cells !== [] ? 'Sampled — counts are lower bounds.' : null,
            'error' => $error,
        ], static fn (?string $v): bool => $v !== null));
    }
}
