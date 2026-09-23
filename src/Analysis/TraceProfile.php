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

/**
 * The CPU profile captured for a trace, if any. laravel-telemetry's excimer
 * instrumentation emits a `profile.captured` log event — top functions by
 * sample count — for slow sampled requests/jobs; this reads it back by
 * trace id so the waterfall can say WHERE the time went, not just that it
 * went. Fail-open: no extension, no profile, no problem.
 */
final readonly class TraceProfile
{
    /**
     * How far around the trace to look. A log line or profile event carries the
     * time it happened, which is inside the trace; the padding only absorbs
     * clock skew between hosts, and every extra minute is more chunks to scan.
     */
    private const PADDING_SECONDS = 300;

    public function __construct(private ConnectionManager $connections) {}

    /**
     * @return list<array{name: string, percent: float, count: int}>
     */
    public function forTrace(Trace $trace): array
    {
        $root = $trace->root();

        if ($root === null || preg_match('/^[0-9a-f]{16,32}$/', $trace->traceId) !== 1) {
            return [];
        }

        // Window padded around the trace itself — Loki needs a range, and the
        // profile event lands at the request's end.
        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - self::PADDING_SECONDS);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + self::PADDING_SECONDS);

        try {
            $entries = $this->connections->logs()->query(
                LogQuery::stream(ScopeLabels::logServiceMatcher(array_map('strval', array_keys($trace->services))))
                    ->lineContains('profile.captured')
                    ->whereLabel('trace_id', MatchOp::Eq, $trace->traceId),
                $start,
                $end,
                limit: 3,
            );
        } catch (SourceException) {
            return [];
        }

        foreach ($entries as $entry) {
            if (trim($entry->line) !== 'profile.captured') {
                continue;
            }

            $functions = json_decode($entry->labels['profile_top_functions'] ?? '', true);

            if (! is_array($functions)) {
                continue;
            }

            $profile = [];

            foreach ($functions as $function) {
                if (! is_array($function) || ! is_string($function['name'] ?? null)) {
                    continue;
                }

                $profile[] = [
                    'name' => $function['name'],
                    'percent' => (float) ($function['percent'] ?? 0),
                    'count' => (int) ($function['count'] ?? 0),
                ];
            }

            if ($profile !== []) {
                return $profile;
            }
        }

        return [];
    }
}
