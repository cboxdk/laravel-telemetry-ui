<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\ScopeLabels;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * The log lines written DURING a request — correlated by trace id, so the
 * request page can show "what the app said" next to what it did. Fail-open:
 * a missing logs backend just means no section.
 */
final readonly class TraceLogs
{
    /**
     * How far around the trace to look. A log line or profile event carries the
     * time it happened, which is inside the trace; the padding only absorbs
     * clock skew between hosts, and every extra minute is more chunks to scan.
     */
    private const PADDING_SECONDS = 300;

    public function __construct(private ConnectionManager $connections) {}

    /**
     * @return list<array{time: string, level: string, tone: string, message: string}>
     */
    public function forTrace(Trace $trace): array
    {
        $root = $trace->root();

        if ($root === null || preg_match('/^[0-9a-f]{16,32}$/', $trace->traceId) !== 1) {
            return [];
        }

        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - self::PADDING_SECONDS);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + self::PADDING_SECONDS);

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(ScopeLabels::logServiceMatcher(array_map('strval', array_keys($trace->services))))
                    ->whereLabel('trace_id', MatchOp::Eq, $trace->traceId),
                $start,
                $end,
                limit: 20,
            );
        } catch (SourceException) {
            return [];
        }

        $logs = [];

        foreach ($entries as $entry) {
            $level = strtolower($entry->labels['detected_level'] ?? $entry->labels['severity_text'] ?? 'info');

            $logs[] = [
                'time' => Carbon::createFromTimestamp(intdiv($entry->timestampNano, 1_000_000_000))->format('H:i:s'),
                'level' => $level,
                'tone' => match ($level) {
                    'error', 'fatal', 'critical' => 'danger',
                    'warn', 'warning' => 'warn',
                    default => 'dim',
                },
                'message' => $entry->line,
            ];
        }

        usort($logs, static fn (array $a, array $b): int => strcmp($a['time'], $b['time']));

        return $logs;
    }
}
