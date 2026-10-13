<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;

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
    private const REPEAT_SEARCH_LIMIT = 500;

    /**
     * @return list<RepeatGroup> most traces affected first
     *
     * @throws SourceException
     */
    private function repeatGroups(string $event): array
    {
        [$start, $end] = $this->range();

        $entries = $this->logs()->query(
            $this->logSelector()
                ->lineContains($event)
                ->whereLabel('db_query_text', MatchOp::Neq, ''),
            $start,
            $end,
            limit: self::REPEAT_SEARCH_LIMIT,
        );

        /** @var array<string, RepeatGroup> $groups */
        $groups = [];
        /** @var array<string, array<string, true>> $seen trace ids per group */
        $seen = [];

        foreach ($entries as $entry) {
            if (trim($entry->line) !== $event) {
                continue;
            }

            $query = $entry->labels['db_query_text'] ?? '';

            if ($query === '') {
                continue;
            }

            $shape = $entry->labels['laravel_db_query_shape'] ?? '';
            $key = $shape !== '' ? $shape : $query;

            $row = $groups[$key] ?? [
                'key' => $key,
                'query' => $query,
                'connection' => $entry->labels['laravel_db_connection'] ?? $entry->labels['db_namespace'] ?? '',
                'origin' => '', 'function' => '',
                'traces' => 0, 'worstRepeat' => 0, 'lastNano' => 0, 'traceId' => '',
            ];

            // One trace with three duplicated binding sets of one shape is one
            // trace affected, not three. A record without a trace id counts
            // on its own.
            $traceId = $entry->labels['trace_id'] ?? '';
            $traceKey = $traceId !== '' ? $traceId : 'record:'.$entry->timestampNano.':'.count($seen[$key] ?? []);

            if (! isset($seen[$key][$traceKey])) {
                $seen[$key][$traceKey] = true;
                $row['traces']++;
            }

            $row['worstRepeat'] = max($row['worstRepeat'], (int) ($entry->labels['db_query_repeat_count'] ?? 0));

            if ($entry->timestampNano >= $row['lastNano']) {
                $row['lastNano'] = $entry->timestampNano;
                $row['traceId'] = $entry->labels['trace_id'] ?? '';
                // The newest example text and origin: the code as it is now.
                $row['query'] = $query;
                [$row['origin'], $row['function']] = self::origin($entry) ?? [$row['origin'], $row['function']];
            } elseif ($row['origin'] === '') {
                [$row['origin'], $row['function']] = self::origin($entry) ?? ['', ''];
            }

            $groups[$key] = $row;
        }

        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => $b['traces'] <=> $a['traces'] ?: $b['worstRepeat'] <=> $a['worstRepeat']);

        return $rows;
    }

    /**
     * `file:line` and the function, when the record names the app code.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function origin(LogEntry $entry): ?array
    {
        $file = $entry->labels['code_file_path'] ?? '';

        if ($file === '') {
            return null;
        }

        $line = $entry->labels['code_line_number'] ?? '';

        return [$file.($line !== '' ? ':'.$line : ''), $entry->labels['code_function_name'] ?? ''];
    }
}
