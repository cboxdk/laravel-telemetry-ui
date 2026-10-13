<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsQueryRepeats;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * N+1 queries: one statement shape run over and over with different bindings
 * within one trace — a lazy-loaded relation inside a loop. laravel-telemetry
 * emits `db.query.n_plus_one_detected` once per shape per trace at the repeat
 * threshold, with the app-code line that ran it; this groups those records by
 * shape so the fix (eager loading at that line) is one click from the row.
 */
final class NPlusOneQueries extends Panel
{
    use ReadsQueryRepeats;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $rows = [];
        $error = null;

        try {
            $rows = $this->repeatGroups('db.query.n_plus_one_detected');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static function (array $row): array {
            $trace = $row['traceId'] !== '' ? Ui::trace($row['traceId']) : null;

            return [
                'query' => Ui::cell($row['query'], ['mono' => true]),
                'origin' => Ui::cell($row['origin'] !== '' ? $row['origin'] : '—', $row['function'] !== '' ? ['mono' => true, 'sub' => $row['function']] : ['mono' => true]),
                'traces' => Ui::cell(Format::count($row['traces']), ['raw' => $row['traces'], 'tone' => 'warn']),
                'repeat' => Ui::cell('×'.$row['worstRepeat'], ['raw' => $row['worstRepeat'], 'tone' => 'danger']),
                'trace' => $trace !== null
                    ? Ui::cell(substr($row['traceId'], 0, 8).'…', ['mono' => true, 'link' => $trace])
                    : Ui::cell('—'),
                '_link' => Ui::entity('query', $row['query']),
            ];
        }, array_slice($rows, 0, 50));

        return Ui::table('N+1 queries', [
            Ui::col('query', 'Query (example)'),
            Ui::col('origin', 'Origin'),
            Ui::num('traces', 'Traces affected'),
            Ui::num('repeat', 'Worst repeat'),
            Ui::num('trace', 'Trace'),
        ], $table, array_filter([
            'subtitle' => 'One query shape run over and over with different bindings in one trace, grouped by shape, with the line that ran it',
            'error' => $error,
            'empty' => 'No N+1 detections in this period. They need laravel-telemetry 3.5 or later; older versions reported them as duplicates.',
            'note' => 'Fired once per query shape per trace when it crosses the repeat threshold (default 3). Fix with eager loading (with(), load()) at the origin line.',
        ], static fn ($v): bool => $v !== null));
    }
}
