<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Span;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * The unified errors list — every exception, frontend and backend, grouped by
 * `exception.group`. Both cboxdk/laravel-telemetry's backend handler and its
 * browser SDK stamp that fingerprint (class + top in-app frame) with the SAME
 * algorithm, so a JS error and a PHP error that are "the same issue" collapse
 * into one row. This is the Sentry-style issues list, on open data: each row
 * drills straight into a representative trace (→ waterfall + host context).
 *
 * Trace-sourced (metrics can't unify — frontend errors exist only as spans),
 * so counts are over a bounded recent sample, not the exact retention total.
 */
final class UnifiedErrors extends Card
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 100;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $traceql = '{ '.$this->traceScope('span.exception.group != nil')
                .' } | select(span.exception.group, span.exception.type, span.exception.message, span.browser)';

            $results = $this->traces()->search($traceql, $start, $end, limit: self::SEARCH_LIMIT);

            /** @var array<string, array{group: string, type: string, message: string, count: int, frontend: bool, backend: bool, lastNano: int, traceId: string}> $groups */
            $groups = [];

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $group = $this->str($span->attributes['exception.group'] ?? null);

                    if ($group === null) {
                        continue;
                    }

                    $row = $groups[$group] ?? [
                        'group' => $group, 'type' => '', 'message' => '', 'count' => 0,
                        'frontend' => false, 'backend' => false, 'lastNano' => 0, 'traceId' => '',
                    ];

                    $row['count']++;
                    Span::attributesAreBrowser($span->attributes) ? $row['frontend'] = true : $row['backend'] = true;

                    // Keep the most recent occurrence as the representative one.
                    if ($span->startNano >= $row['lastNano']) {
                        $row['lastNano'] = $span->startNano;
                        $row['type'] = $this->str($span->attributes['exception.type'] ?? null) ?? $row['type'];
                        $row['message'] = $this->str($span->attributes['exception.message'] ?? null) ?? $row['message'];
                        $row['traceId'] = $summary->traceId;
                    }

                    $groups[$group] = $row;
                }
            }

            $rows = array_values($groups);
            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['lastNano'] <=> $a['lastNano']);

            $rows = array_map(fn (array $row): array => [
                ...$row,
                'source' => $this->source($row['frontend'], $row['backend']),
                'lastSeen' => Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(),
                'tracesUrl' => $this->tracesUrl($row['group']),
            ], $rows);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.unified-errors';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'sampled' => count($rows) >= self::SEARCH_LIMIT,
        ]);
    }

    /**
     * All error traces for one group, for the "see every occurrence" drill.
     */
    private function tracesUrl(string $group): string
    {
        return $this->pageUrl('traces', [
            'q' => '{ span.exception.group = "'.addcslashes($group, '"\\').'" }',
        ]);
    }

    private function source(bool $frontend, bool $backend): string
    {
        return match (true) {
            $frontend && $backend => 'full-stack',
            $frontend => 'frontend',
            default => 'backend',
        };
    }
}
