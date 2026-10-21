<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\ScopeLabels;
use DateTimeImmutable;

/**
 * The exceptions a trace threw — "why it failed" in the trace story. Backend
 * exceptions live in Loki records (authoritative, with file:line and group),
 * browser ones on exception spans; both come back as one list, deduplicated
 * by fingerprint, each linking to its error group.
 *
 * Each names the spans that threw it (`spans`, in the trace's own span id
 * form), so the waterfall can show the exception on the span: a job retried
 * thirty times throws the same group thirty times, once per attempt.
 *
 * @phpstan-type TraceException array{group: string, type: string, message: string, file: string, line: int, source: string, match: string, spans: list<string>, stacktrace: string}
 * @phpstan-type DetailedTraceException array{group: string, type: string, message: string, file: string, line: int, source: string, match: string, spans: list<string>, stacktrace: string, code: string}
 */
final readonly class TraceExceptions
{
    /** Characters of stacktrace the trace drawer gets; the Markdown snapshot gets it whole. */
    private const STACK_LIMIT = 8000;

    public function __construct(private ConnectionManager $connections) {}

    /**
     * @return list<TraceException>
     */
    public function forTrace(Trace $trace): array
    {
        return array_map(static function (array $exception): array {
            unset($exception['code']);
            $exception['stacktrace'] = mb_substr($exception['stacktrace'], 0, self::STACK_LIMIT);

            return $exception;
        }, $this->detailed($trace));
    }

    /**
     * {@see forTrace()} plus the source excerpt around the throw
     * (`exception_source`) and the whole stacktrace, each exactly as stored —
     * empty when the emitter sent none. For the Markdown snapshot; the trace
     * drawer doesn't need the extra weight.
     *
     * @return list<DetailedTraceException>
     */
    public function detailed(Trace $trace): array
    {
        $root = $trace->root();
        $out = [];

        foreach ($trace->spans as $span) {
            $type = $span->attributes['exception.type'] ?? null;

            if ($span->isBrowser() && is_scalar($type) && (string) $type !== '') {
                $group = (string) ($span->attributes['exception.group'] ?? '');
                $out[$group !== '' ? $group : 'span:'.$span->spanId] = [
                    'group' => $group,
                    'type' => (string) $type,
                    'message' => (string) ($span->attributes['exception.message'] ?? ''),
                    'file' => (string) ($span->attributes['exception.file'] ?? ''),
                    'line' => (int) ($span->attributes['exception.line'] ?? 0),
                    'source' => 'frontend',
                    'match' => 'span',
                    'spans' => [$span->spanId],
                    'stacktrace' => is_scalar($stack = $span->attributes['exception.stacktrace'] ?? null) ? (string) $stack : '',
                    'code' => '',
                ];
            }
        }

        if ($root === null || preg_match('/^[0-9a-f]{16,32}$/', $trace->traceId) !== 1) {
            return array_values($out);
        }

        // The trace names its own services, so the stream selector can too:
        // a pipeline filter over every service's logs makes the backend
        // decompress the whole window. The margin covers a record written a
        // little after the span it belongs to, not a wider search.
        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - 60);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 60);

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(self::serviceMatcher($trace))
                    ->whereLabel('trace_id', MatchOp::Eq, $trace->traceId)
                    ->whereLabel('exception_group', MatchOp::Neq, ''),
                $start,
                $end,
                limit: 100,
            );
        } catch (SourceException) {
            return array_values($out);
        }

        $matched = 'trace';
        $hexIds = $trace->spans !== [] && preg_match('/^[0-9a-f]{16}$/i', $trace->spans[0]->spanId) === 1;

        // Records without trace context (sampled-away traces, a backend that
        // drops OTLP trace ids): fall back to the same service throwing
        // within the root span's own time window — marked as a likely match.
        if ($entries === [] && $root->serviceName !== '') {
            $from = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000));
            $to = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 1);

            try {
                $entries = $this->connections->logs()->query(
                    LogQuery::stream(new LabelMatcher(ScopeLabels::logs('service'), MatchOp::Eq, $root->serviceName))
                        ->whereLabel('exception_group', MatchOp::Neq, ''),
                    $from,
                    $to,
                    limit: 5,
                );
            } catch (SourceException) {
                $entries = [];
            }

            $entries = array_values(array_filter(
                $entries,
                static fn ($e): bool => $e->timestampNano >= $root->startNano && $e->timestampNano <= $root->endNano + 5_000_000,
            ));
            $matched = 'time';
        }

        foreach ($entries as $entry) {
            $group = $entry->labels['exception_group'] ?? '';

            if ($group === '') {
                continue;
            }

            $spanId = $matched === 'trace' ? self::spanId($entry->labels['span_id'] ?? '', $hexIds) : null;

            if (isset($out[$group])) {
                if ($spanId !== null && ! in_array($spanId, $out[$group]['spans'], true)) {
                    $out[$group]['spans'][] = $spanId;
                }

                continue;
            }

            $out[$group] = [
                'group' => $group,
                'type' => $entry->labels['exception_type'] ?? 'Exception',
                'message' => $entry->labels['exception_message'] ?? '',
                'file' => $entry->labels['exception_file'] ?? '',
                'line' => (int) ($entry->labels['exception_line'] ?? 0),
                'source' => 'backend',
                'match' => $matched,
                'spans' => $spanId !== null ? [$spanId] : [],
                'stacktrace' => $entry->labels['exception_stacktrace'] ?? '',
                'code' => $entry->labels['exception_source'] ?? '',
            ];
        }

        return array_values($out);
    }

    /**
     * Log lines from the trace's service inside its root span's window — the
     * fallback when log records carry no trace id to join on.
     *
     * @return list<array{time: string, level: string, tone: string, message: string}>
     */
    public function logsByWindow(Trace $trace): array
    {
        $root = $trace->root();

        if ($root === null || $root->serviceName === '') {
            return [];
        }

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(new LabelMatcher(ScopeLabels::logs('service'), MatchOp::Eq, $root->serviceName)),
                (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000)),
                (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 1),
                limit: 50,
            );
        } catch (SourceException) {
            return [];
        }

        $logs = [];

        foreach ($entries as $entry) {
            if ($entry->timestampNano < $root->startNano || $entry->timestampNano > $root->endNano + 5_000_000) {
                continue;
            }

            $row = LogExplorer::row($entry);
            $logs[] = ['time' => substr($row['time'], 11, 12), 'level' => $row['level'], 'tone' => $row['tone'], 'message' => $row['message'], 'nano' => $row['nano']];
        }

        usort($logs, static fn (array $a, array $b): int => strcmp($a['nano'], $b['nano']));

        return array_map(static fn (array $l): array => ['time' => $l['time'], 'level' => $l['level'], 'tone' => $l['tone'], 'message' => $l['message']], array_slice($logs, 0, 20));
    }

    /**
     * A record's span id in the form the trace gives its spans. OTLP log
     * records carry it as hex (Loki stores `span_id` so); Tempo's trace API
     * answers base64. Null when it is not a span id at all.
     */
    private static function spanId(string $hex, bool $traceUsesHex): ?string
    {
        if (preg_match('/^[0-9a-f]{16}$/i', $hex) !== 1) {
            return null;
        }

        return $traceUsesHex ? strtolower($hex) : base64_encode((string) hex2bin($hex));
    }

    /** The trace's own services as one stream selector (see {@see ScopeLabels::logServiceMatcher()}). */
    private static function serviceMatcher(Trace $trace): LabelMatcher
    {
        return ScopeLabels::logServiceMatcher(array_map('strval', array_keys($trace->services)));
    }
}
