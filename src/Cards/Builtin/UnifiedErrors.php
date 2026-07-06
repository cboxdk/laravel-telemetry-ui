<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The unified errors list — every exception, frontend and backend, grouped by
 * `exception.group`. This is the Sentry-style issues list, on open data: each
 * row carries its in-period trend sparkline, first/last seen (with a NEW
 * badge for groups born in the last 24h) and drills into the error-group
 * detail drawer (stacktrace, source context, occurrences, suspect deploy).
 *
 * Two sources, because the two runtimes record errors differently:
 * - Backend: laravel-telemetry's report() hook emits a structured exception
 *   record (OTLP log → Loki) with the fingerprint — present even when the
 *   trace was sampled away, so this is the authoritative feed.
 * - Frontend: browser exception spans (Tempo) carry type/message/file/line
 *   but no fingerprint; the UI computes the identical one read-side
 *   ({@see ExceptionFingerprint}).
 *
 * The search window extends beyond the page period (min 7 days) so "first
 * seen" means what it says; counts and trends stay period-scoped. Counts
 * are over a bounded recent sample, not the exact retention total.
 */
final class UnifiedErrors extends Card
{
    use CoercesAttributes;

    private const SEARCH_LIMIT = 500;

    private const TRACE_SEARCH_LIMIT = 100;

    /** Sparkline resolution: buckets across the page period. */
    private const BUCKETS = 24;

    /** Minimum lookback so first-seen isn't clipped to the page period. */
    private const FIRST_SEEN_LOOKBACK_DAYS = 7;

    #[Url(as: 'err_sort')]
    public string $sort = 'count';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $lookbackStart = min(
            $start->getTimestamp(),
            (new DateTimeImmutable('-'.self::FIRST_SEEN_LOOKBACK_DAYS.' days'))->getTimestamp(),
        );
        $searchStart = (new DateTimeImmutable)->setTimestamp($lookbackStart);

        $fromNano = $start->getTimestamp() * 1_000_000_000;
        $toNano = $end->getTimestamp() * 1_000_000_000;

        $rows = [];
        $error = null;
        $truncated = false;

        try {
            /** @var array<string, array{group: string, type: string, message: string, count: int, frontend: bool, backend: bool, firstNano: int, lastNano: int, buckets: array<int, int>}> $groups */
            $groups = [];

            // Backend: structured exception records in Loki. The fingerprint
            // lives in structured metadata, so filter on the label — a
            // missing label reads as "", which also skips ordinary log lines.
            $entries = $this->logs()->query(
                $this->logSelector().' | exception_group != ""',
                $searchStart,
                $end,
                limit: self::SEARCH_LIMIT,
            );

            $truncated = count($entries) >= self::SEARCH_LIMIT;

            foreach ($entries as $entry) {
                $group = $entry->labels['exception_group'] ?? '';

                if ($group === '') {
                    continue;
                }

                $this->fold($groups, $group, $entry->timestampNano, $fromNano, $toNano, frontend: false, attributes: [
                    'type' => $entry->labels['exception_type'] ?? '',
                    'message' => $entry->labels['exception_message'] ?? '',
                ]);
            }

            // Frontend: browser exception spans, grouped by the computed
            // fingerprint (the ingest doesn't stamp one).
            $traceql = '{ '.$this->traceScope('span.browser = true && span.exception.type != nil')
                .' } | select(span.exception.type, span.exception.message, span.exception.file, span.exception.line)';

            $results = $this->traces()->search($traceql, $searchStart, $end, limit: self::TRACE_SEARCH_LIMIT);

            $truncated = $truncated || count($results) >= self::TRACE_SEARCH_LIMIT;

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

                    $this->fold($groups, $group, $span->startNano, $fromNano, $toNano, frontend: true, attributes: [
                        'type' => $type,
                        'message' => $this->str($span->attributes['exception.message'] ?? null) ?? '',
                    ]);
                }
            }

            // Only groups active in the page period make the list; the wider
            // window exists purely so first-seen isn't clipped.
            $rows = array_values(array_filter($groups, static fn (array $row): bool => $row['count'] > 0));

            $newCutoffNano = (time() - 86_400) * 1_000_000_000;

            $rows = array_map(fn (array $row): array => [
                ...$row,
                'source' => $this->source($row['frontend'], $row['backend']),
                'firstSeen' => Carbon::createFromTimestamp(intdiv($row['firstNano'], 1_000_000_000))->diffForHumans(),
                'lastSeen' => Carbon::createFromTimestamp(intdiv($row['lastNano'], 1_000_000_000))->diffForHumans(),
                // NEW = born within 24h — suppressed when the sample was
                // truncated (the real first occurrence may be older).
                'isNew' => ! $truncated && $row['firstNano'] >= $newCutoffNano,
            ], $rows);

            usort($rows, match ($this->sort) {
                'last' => static fn (array $a, array $b): int => $b['lastNano'] <=> $a['lastNano'],
                'new' => static fn (array $a, array $b): int => $b['firstNano'] <=> $a['firstNano'],
                default => static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['lastNano'] <=> $a['lastNano'],
            });
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.unified-errors';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'sampled' => $truncated,
        ]);
    }

    /**
     * Merge one occurrence into its group. The wider search window feeds
     * first/last seen; only in-period occurrences feed count + trend.
     *
     * @param  array<string, array{group: string, type: string, message: string, count: int, frontend: bool, backend: bool, firstNano: int, lastNano: int, buckets: array<int, int>}>  $groups
     * @param  array{type: string, message: string}  $attributes
     */
    private function fold(array &$groups, string $group, int $nano, int $fromNano, int $toNano, bool $frontend, array $attributes): void
    {
        $row = $groups[$group] ?? [
            'group' => $group, 'type' => '', 'message' => '', 'count' => 0,
            'frontend' => false, 'backend' => false,
            'firstNano' => PHP_INT_MAX, 'lastNano' => 0,
            'buckets' => array_fill(0, self::BUCKETS, 0),
        ];

        $frontend ? $row['frontend'] = true : $row['backend'] = true;
        $row['firstNano'] = min($row['firstNano'], $nano);

        if ($nano >= $fromNano && $nano <= $toNano) {
            $row['count']++;

            $span = max(1, $toNano - $fromNano);
            $bucket = min(self::BUCKETS - 1, (int) (($nano - $fromNano) / $span * self::BUCKETS));
            $row['buckets'][$bucket]++;
        }

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
