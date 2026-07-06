<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Trace;
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
        $start = (new DateTimeImmutable)->setTimestamp(intdiv($root->startNano, 1_000_000_000) - 3600);
        $end = (new DateTimeImmutable)->setTimestamp(intdiv($root->endNano, 1_000_000_000) + 3600);

        try {
            $entries = $this->connections->logs()->query(
                '{service_name=~".+"} |= "profile.captured" | trace_id="'.$trace->traceId.'"',
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
