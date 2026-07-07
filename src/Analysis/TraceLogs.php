<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\Trace;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * The log lines written DURING a request — correlated by trace id, so the
 * request page can show "what the app said" next to what it did. Fail-open:
 * a missing logs backend just means no section.
 */
final readonly class TraceLogs
{
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

        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - 3600);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 3600);

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(new LabelMatcher('service_name', MatchOp::Re, '.+'))
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
