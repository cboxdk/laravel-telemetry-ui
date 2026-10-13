<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;

/**
 * Reads laravel-telemetry's repeat-detection log events back and groups them
 * by the emitter's shape: one record per shape (or per distinct call) per
 * trace, at the repeat threshold. `traces` counts distinct trace ids, never
 * records — one trace can hold several records of one shape.
 *
 * The record family is named by its labels, so the same reader serves the
 * query events ({@see ReadsQueryRepeats}) and the outgoing-call event
 * (`http.client.n_plus_one_detected`):
 *
 *  - `text`: the label every record of the family carries (the statement,
 *    the URL template) — the Loki read requires it, and a group without a
 *    shape falls back to it;
 *  - `shape`: the emitter's hash of the normalised text, the group key;
 *  - `count`: how many times it ran in the trace;
 *  - `context`: further labels kept from the newest record, as is.
 *
 * @phpstan-type RepeatLabels array{text: string, shape: string, count: string, context?: list<string>}
 * @phpstan-type RepeatRecordGroup array{key: string, text: string, context: array<string, string>, origin: string, function: string, traces: int, worstRepeat: int, lastNano: int, traceId: string}
 */
trait ReadsRepeatRecords
{
    private const REPEAT_SEARCH_LIMIT = 500;

    /**
     * @param  RepeatLabels  $labels
     * @return list<RepeatRecordGroup> most traces affected first
     *
     * @throws SourceException
     */
    private function repeatRecordGroups(string $event, array $labels): array
    {
        [$start, $end] = $this->range();

        $entries = $this->logs()->query(
            $this->logSelector()
                ->lineContains($event)
                ->whereLabel($labels['text'], MatchOp::Neq, ''),
            $start,
            $end,
            limit: self::REPEAT_SEARCH_LIMIT,
        );

        /** @var array<string, RepeatRecordGroup> $groups */
        $groups = [];
        /** @var array<string, array<string, true>> $seen trace ids per group */
        $seen = [];

        foreach ($entries as $entry) {
            if (trim($entry->line) !== $event) {
                continue;
            }

            $text = $entry->labels[$labels['text']] ?? '';

            if ($text === '') {
                continue;
            }

            $shape = $entry->labels[$labels['shape']] ?? '';
            $key = $shape !== '' ? $shape : $text;

            $row = $groups[$key] ?? [
                'key' => $key,
                'text' => $text,
                'context' => self::repeatContext($entry, $labels),
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

            $row['worstRepeat'] = max($row['worstRepeat'], (int) ($entry->labels[$labels['count']] ?? 0));

            if ($entry->timestampNano >= $row['lastNano']) {
                $row['lastNano'] = $entry->timestampNano;
                $row['traceId'] = $entry->labels['trace_id'] ?? '';
                // The newest example and origin: the code as it is now.
                $row['text'] = $text;
                $row['context'] = self::repeatContext($entry, $labels);
                [$row['origin'], $row['function']] = self::repeatOrigin($entry) ?? [$row['origin'], $row['function']];
            } elseif ($row['origin'] === '') {
                [$row['origin'], $row['function']] = self::repeatOrigin($entry) ?? ['', ''];
            }

            $groups[$key] = $row;
        }

        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => $b['traces'] <=> $a['traces'] ?: $b['worstRepeat'] <=> $a['worstRepeat']);

        return $rows;
    }

    /**
     * @param  RepeatLabels  $labels
     * @return array<string, string>
     */
    private static function repeatContext(LogEntry $entry, array $labels): array
    {
        $context = [];

        foreach ($labels['context'] ?? [] as $label) {
            $context[$label] = $entry->labels[$label] ?? '';
        }

        return $context;
    }

    /**
     * `file:line` and the function, when the record names the app code.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function repeatOrigin(LogEntry $entry): ?array
    {
        $file = $entry->labels['code_file_path'] ?? '';

        if ($file === '') {
            return null;
        }

        $line = $entry->labels['code_line_number'] ?? '';

        return [$file.($line !== '' ? ':'.$line : ''), $entry->labels['code_function_name'] ?? ''];
    }
}
