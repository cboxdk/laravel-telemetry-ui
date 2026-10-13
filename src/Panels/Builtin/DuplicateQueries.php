<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsQueryRepeats;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Identical queries: the same statement with the same bindings run again
 * within one trace — work a memo or the query cache would have saved.
 * laravel-telemetry emits `db.query.duplicate_detected` once per distinct
 * statement-and-bindings per trace when it crosses the repeat threshold; this
 * groups those records by query shape and counts the distinct traces.
 *
 * Before laravel-telemetry 3.5 split out `db.query.n_plus_one_detected`, this
 * event ignored bindings and so fired for N+1s too; records from those
 * versions still land here, grouped by their statement text. N+1s from newer
 * versions are on {@see NPlusOneQueries}.
 */
final class DuplicateQueries extends Panel
{
    use ReadsQueryRepeats;

    public static function span(): int
    {
        return 1;
    }

    public function data(): array
    {
        $rows = [];
        $error = null;

        try {
            $rows = $this->repeatGroups('db.query.duplicate_detected');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static function (array $row): array {
            $trace = $row['traceId'] !== '' ? Ui::trace($row['traceId']) : null;

            return [
                'query' => Ui::cell($row['query'], $row['origin'] !== '' ? ['mono' => true, 'sub' => $row['origin']] : ['mono' => true]),
                'traces' => Ui::cell(Format::count($row['traces']), ['raw' => $row['traces'], 'tone' => 'warn']),
                'repeat' => Ui::cell('×'.$row['worstRepeat'], ['raw' => $row['worstRepeat'], 'tone' => 'warn']),
                'trace' => $trace !== null
                    ? Ui::cell(substr($row['traceId'], 0, 8).'…', ['mono' => true, 'link' => $trace])
                    : Ui::cell('—'),
                '_link' => Ui::entity('query', $row['query']),
            ];
        }, array_slice($rows, 0, 50));

        return Ui::table('Duplicate queries', [
            Ui::col('query', 'Query'),
            Ui::num('traces', 'Traces'),
            Ui::num('repeat', 'Worst'),
            Ui::num('trace', 'Trace'),
        ], $table, array_filter([
            'subtitle' => 'The same SQL with the same bindings, run again within one trace',
            'error' => $error,
            'empty' => 'No identical repeated queries in this period.',
            'note' => 'Recorded once per distinct statement and bindings per trace when it crosses the repeat threshold (default 3); Traces counts each trace once. Fix by keeping the first result (a variable, once(), a memo). Before laravel-telemetry 3.5 this record ignored bindings, so older records include N+1s.',
        ], static fn ($v): bool => $v !== null));
    }
}
