<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * The errors seen on a single page — browser exception spans stamped with this
 * page's URL (matched on `http.url`, since `url.path` is only on the backend
 * span), grouped by the same read-side fingerprint the unified errors list uses
 * ({@see ExceptionFingerprint}). Backend records don't carry the concrete path,
 * so this is the frontend (RUM) slice; each row drills into that issue's own
 * page. Empty state when the page is clean.
 */
final class PageDetailErrors extends Card
{
    use CoercesAttributes;
    use ScopesToPage;

    private const TRACE_SEARCH_LIMIT = 100;

    /** Sparkline resolution: buckets across the page period. */
    private const BUCKETS = 24;

    public function render(): View
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

                $traceql = '{ '.$this->traceScope('span.browser = true && span.exception.type != nil')
                    .' } | select(span.http.url, span.exception.type, span.exception.message, span.exception.file, span.exception.line)';

                $results = $this->traces()->search($traceql, $start, $end, limit: self::TRACE_SEARCH_LIMIT);

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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.page-detail-errors';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'sampled' => $truncated,
        ]);
    }

    /**
     * The group's own issue page.
     */
    public function showUrl(string $group): string
    {
        return $this->pageUrl('error-detail', ['group' => $group]);
    }
}
