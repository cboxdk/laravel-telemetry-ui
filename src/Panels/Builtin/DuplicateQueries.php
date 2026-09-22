<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Support\Format;

/**
 * N+1 smells: queries that ran identically more than the configured threshold
 * within one trace. laravel-telemetry emits a `db.query.duplicate_detected`
 * log event (once per distinct query, at the threshold crossing) carrying the
 * parameterized SQL — this reads those back and groups by query text.
 */
final class DuplicateQueries extends Panel
{
    private const SEARCH_LIMIT = 500;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $entries = $this->logs()->query(
                $this->logSelector()->whereLabel('db_query_text', MatchOp::Neq, ''),
                $start,
                $end,
                limit: self::SEARCH_LIMIT,
            );

            /** @var array<string, array{query: string, connection: string, traces: int, worstRepeat: int, lastNano: int, traceId: string}> $groups */
            $groups = [];

            foreach ($entries as $entry) {
                if (trim($entry->line) !== 'db.query.duplicate_detected') {
                    continue;
                }

                $query = $entry->labels['db_query_text'] ?? '';

                if ($query === '') {
                    continue;
                }

                $row = $groups[$query] ?? [
                    'query' => $query,
                    'connection' => $entry->labels['db_namespace'] ?? '',
                    'traces' => 0, 'worstRepeat' => 0, 'lastNano' => 0, 'traceId' => '',
                ];

                $row['traces']++;
                $row['worstRepeat'] = max($row['worstRepeat'], (int) ($entry->labels['db_query_repeat_count'] ?? 0));

                if ($entry->timestampNano >= $row['lastNano']) {
                    $row['lastNano'] = $entry->timestampNano;
                    $row['traceId'] = $entry->labels['trace_id'] ?? '';
                }

                $groups[$query] = $row;
            }

            $rows = array_values($groups);
            usort($rows, static fn (array $a, array $b): int => $b['traces'] <=> $a['traces'] ?: $b['worstRepeat'] <=> $a['worstRepeat']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static function (array $row): array {
            $trace = $row['traceId'] !== '' ? Ui::trace($row['traceId']) : null;

            return array_filter([
                'query' => Ui::cell($row['query'], ['mono' => true]),
                'connection' => Ui::cell($row['connection'] !== '' ? $row['connection'] : '—'),
                'traces' => Ui::cell(Format::count($row['traces']), ['raw' => $row['traces'], 'tone' => 'warn']),
                'repeat' => Ui::cell('×'.$row['worstRepeat'], ['raw' => $row['worstRepeat'], 'tone' => 'danger']),
                'trace' => $trace !== null
                    ? Ui::cell(substr($row['traceId'], 0, 8).'…', ['mono' => true, 'link' => $trace])
                    : Ui::cell('—'),
                '_link' => $trace,
            ], static fn ($v): bool => $v !== null);
        }, array_slice($rows, 0, 50));

        return Ui::table('Duplicate queries (N+1)', [
            Ui::col('query', 'Query'),
            Ui::col('connection', 'Connection'),
            Ui::num('traces', 'Traces affected'),
            Ui::num('repeat', 'Worst repeat'),
            Ui::num('trace', 'Trace'),
        ], $table, array_filter([
            'subtitle' => 'Queries that repeated identically within one trace — the classic N+1 smell, named',
            'error' => $error,
            'empty' => 'No duplicate-query detections in this period. 🎉',
            'note' => 'Fired once per distinct query when it crosses the repeat threshold (default 3). Fix with eager loading or caching.',
        ], static fn ($v): bool => $v !== null));
    }
}
