<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeImmutable;

/**
 * A search for root spans: Tempo's `nestedSetParent < 0` intrinsic, which
 * keeps one hit per trace and names it by its entry point.
 *
 * Not every TraceQL backend has it — telemetryd's parser refuses the
 * intrinsic — so a refusal is retried without it, and each hit's root span
 * (the matched span named like the trace's root) is moved to the front, so
 * callers that read tallies off `matchedSpans[0]` still read the root's when
 * the backend returned it.
 */
final class RootSearch
{
    /**
     * @param  list<TraceCondition>  $conditions
     * @param  list<string>  $select
     * @return list<TraceSummary>
     *
     * @throws SourceException
     */
    public static function search(TracesSource $source, array $conditions, DateTimeImmutable $start, DateTimeImmutable $end, int $limit, array $select = []): array
    {
        try {
            return array_values($source->search(new TraceQuery([self::root(), ...$conditions], $select), $start, $end, $limit));
        } catch (SourceException $exception) {
            if (! self::refusedTheIntrinsic($exception)) {
                throw $exception;
            }
        }

        return array_map(
            self::rootFirst(...),
            array_values($source->search(new TraceQuery($conditions, $select), $start, $end, $limit)),
        );
    }

    private static function root(): TraceCondition
    {
        return TraceCondition::token('nestedSetParent', TraceOp::Lt, '0');
    }

    /** A 400 or a parse error: the query, not the backend, is the problem. */
    private static function refusedTheIntrinsic(SourceException $exception): bool
    {
        $detail = mb_strtolower($exception->detail.' '.$exception->getMessage());

        return $exception->httpStatus === 400
            || str_contains($detail, 'nestedsetparent')
            || str_contains($detail, 'parse error')
            || str_contains($detail, 'syntax error');
    }

    private static function rootFirst(TraceSummary $summary): TraceSummary
    {
        $spans = $summary->matchedSpans;

        foreach ($spans as $i => $span) {
            if ($span->name === $summary->rootTraceName) {
                unset($spans[$i]);

                return new TraceSummary($summary->traceId, $summary->rootServiceName, $summary->rootTraceName, $summary->startedAt, $summary->durationMs, [$span, ...array_values($spans)]);
            }
        }

        return $summary;
    }
}
