<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\Trace;
use DateTimeImmutable;

/**
 * The exceptions a trace threw — "why it failed" in the trace story. Backend
 * exceptions live in Loki records (authoritative, with file:line and group),
 * browser ones on exception spans; both come back as one list, deduplicated
 * by fingerprint, each linking to its error group.
 *
 * @phpstan-type TraceException array{group: string, type: string, message: string, file: string, line: int, source: string, match: string}
 */
final readonly class TraceExceptions
{
    public function __construct(private ConnectionManager $connections) {}

    /**
     * @return list<TraceException>
     */
    public function forTrace(Trace $trace): array
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
                ];
            }
        }

        if ($root === null || preg_match('/^[0-9a-f]{16,32}$/', $trace->traceId) !== 1) {
            return array_values($out);
        }

        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - 600);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 600);

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(new LabelMatcher('service_name', MatchOp::Re, '.+'))
                    ->whereLabel('trace_id', MatchOp::Eq, $trace->traceId)
                    ->whereLabel('exception_group', MatchOp::Neq, ''),
                $start,
                $end,
                limit: 20,
            );
        } catch (SourceException) {
            return array_values($out);
        }

        $matched = 'trace';

        // Records without trace context (sampled-away traces, a backend that
        // drops OTLP trace ids): fall back to the same service throwing
        // within the root span's own time window — marked as a likely match.
        if ($entries === [] && $root->serviceName !== '') {
            $from = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000));
            $to = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 1);

            try {
                $entries = $this->connections->logs()->query(
                    LogQuery::stream(new LabelMatcher('service_name', MatchOp::Eq, $root->serviceName))
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

            if ($group === '' || isset($out[$group])) {
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
                LogQuery::stream(new LabelMatcher('service_name', MatchOp::Eq, $root->serviceName)),
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
}
