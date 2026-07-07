<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Illuminate\Contracts\View\View;

/**
 * N+1 smells: queries that ran identically more than the configured threshold
 * within one trace. laravel-telemetry emits a `db.query.duplicate_detected`
 * log event (once per distinct query, at the threshold crossing) carrying the
 * parameterized SQL — this reads those back and groups by query text.
 */
final class DuplicateQueries extends Card
{
    private const SEARCH_LIMIT = 500;

    public function render(): View
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.duplicate-queries';

        return view($view, [
            'rows' => array_slice($rows, 0, 50),
            'error' => $error,
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
