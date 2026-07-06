<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * The unified errors list — every exception, frontend and backend, grouped by
 * `exception.group`. This is the Sentry-style issues list, on open data: each
 * row drills into the error-group detail drawer (stacktrace, source context,
 * occurrences).
 *
 * Two sources, because the two runtimes record errors differently:
 * - Backend: laravel-telemetry's report() hook emits a structured exception
 *   record (OTLP log → Loki) with the fingerprint — present even when the
 *   trace was sampled away, so this is the authoritative feed.
 * - Frontend: browser exception spans (Tempo) carry type/message/file/line
 *   but no fingerprint; the UI computes the identical one read-side
 *   ({@see ExceptionFingerprint}).
 *
 * Counts are over a bounded recent sample, not the exact retention total.
 */
final class UnifiedErrors extends Card
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 500;

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            /** @var array<string, array{group: string, type: string, message: string, count: int, frontend: bool, backend: bool, lastNano: int}> $groups */
            $groups = [];

            // Backend: structured exception records in Loki. The fingerprint
            // lives in structured metadata, so filter on the label — a
            // missing label reads as "", which also skips ordinary log lines.
            $entries = $this->logs()->query(
                $this->logSelector().' | exception_group != ""',
                $start,
                $end,
                limit: self::SEARCH_LIMIT,
            );

            foreach ($entries as $entry) {
                $group = $entry->labels['exception_group'] ?? '';

                if ($group === '') {
                    continue;
                }

                $this->fold($groups, $group, $entry->timestampNano, frontend: false, attributes: [
                    'type' => $entry->labels['exception_type'] ?? '',
                    'message' => $entry->labels['exception_message'] ?? '',
                ]);
            }

            // Frontend: browser exception spans, grouped by the computed
            // fingerprint (the ingest doesn't stamp one).
            $traceql = '{ '.$this->traceScope('span.browser = true && span.exception.type != nil')
                .' } | select(span.exception.type, span.exception.message, span.exception.file, span.exception.line)';

            $results = $this->traces()->search($traceql, $start, $end, limit: 100);

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $type = $this->str($span->attributes['exception.type'] ?? null);

                    if ($type === null) {
                        continue;
                    }

                    $group = ExceptionFingerprint::compute(
                        $type,
                        $this->str($span->attributes['exception.file'] ?? null) ?? '',
                        (int) ($span->attributes['exception.line'] ?? 0),
                    );

                    $this->fold($groups, $group, $span->startNano, frontend: true, attributes: [
                        'type' => $type,
                        'message' => $this->str($span->attributes['exception.message'] ?? null) ?? '',
                    ]);
                }
            }

            $rows = array_values($groups);
            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['lastNano'] <=> $a['lastNano']);

            $rows = array_map(fn (array $row): array => [
                ...$row,
                'source' => $this->source($row['frontend'], $row['backend']),
                'lastSeen' => Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(),
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
     * Merge one occurrence into its group, keeping the newest occurrence's
     * type/message as the representative ones.
     *
     * @param  array<string, array{group: string, type: string, message: string, count: int, frontend: bool, backend: bool, lastNano: int}>  $groups
     * @param  array{type: string, message: string}  $attributes
     */
    private function fold(array &$groups, string $group, int $nano, bool $frontend, array $attributes): void
    {
        $row = $groups[$group] ?? [
            'group' => $group, 'type' => '', 'message' => '', 'count' => 0,
            'frontend' => false, 'backend' => false, 'lastNano' => 0,
        ];

        $row['count']++;
        $frontend ? $row['frontend'] = true : $row['backend'] = true;

        if ($nano >= $row['lastNano']) {
            $row['lastNano'] = $nano;
            $row['type'] = $attributes['type'] !== '' ? $attributes['type'] : $row['type'];
            $row['message'] = $attributes['message'] !== '' ? $attributes['message'] : $row['message'];
        }

        $groups[$group] = $row;
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
