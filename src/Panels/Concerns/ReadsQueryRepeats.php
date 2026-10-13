<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;

/**
 * Reads laravel-telemetry's repeated-query log events back and groups them by
 * query shape: `db.query.n_plus_one_detected` (one statement shape run over
 * and over with different bindings), fired once per shape per trace, and
 * `db.query.duplicate_detected` (the identical statement AND bindings run
 * again), fired once per distinct statement-and-bindings per trace — so one
 * trace can hold several duplicate records of the same shape. Both fire at
 * the repeat threshold; `traces` counts distinct trace ids, never records.
 *
 * The group key is `laravel.db.query.shape` — the emitter's hash of the
 * normalised statement, so `in (?, ?)` and `in (?, ?, ?)` fold together —
 * and the statement text on records from before the shape existed.
 *
 * @phpstan-type RepeatGroup array{key: string, query: string, connection: string, origin: string, function: string, traces: int, worstRepeat: int, lastNano: int, traceId: string}
 */
trait ReadsQueryRepeats
{
    use ReadsRepeatRecords;

    /**
     * @return list<RepeatGroup> most traces affected first
     *
     * @throws SourceException
     */
    private function repeatGroups(string $event): array
    {
        $groups = $this->repeatRecordGroups($event, [
            'text' => 'db_query_text',
            'shape' => 'laravel_db_query_shape',
            'count' => 'db_query_repeat_count',
            'context' => ['laravel_db_connection', 'db_namespace'],
        ]);

        return array_map(static fn (array $group): array => [
            'key' => $group['key'],
            'query' => $group['text'],
            'connection' => ($group['context']['laravel_db_connection'] ?? '') !== ''
                ? $group['context']['laravel_db_connection']
                : ($group['context']['db_namespace'] ?? ''),
            'origin' => $group['origin'],
            'function' => $group['function'],
            'traces' => $group['traces'],
            'worstRepeat' => $group['worstRepeat'],
            'lastNano' => $group['lastNano'],
            'traceId' => $group['traceId'],
        ], $groups);
    }
}
