<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsRepeatRecords;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * N+1 outgoing calls: one endpoint called over and over with a different id,
 * one call after another, within one trace — a loop that fetches a related
 * resource per row instead of asking for them together. laravel-telemetry
 * (3.6+) emits `http.client.n_plus_one_detected` once per call shape per
 * trace at the repeat threshold, with the app-code line that made the call;
 * this groups those records by shape, the HTTP twin of {@see NPlusOneQueries}.
 */
final class OutgoingNPlusOne extends Panel
{
    use ReadsRepeatRecords;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $rows = [];
        $error = null;

        try {
            $rows = $this->repeatRecordGroups('http.client.n_plus_one_detected', [
                'text' => 'url_template',
                'shape' => 'laravel_http_client_shape',
                'count' => 'http_client_repeat_count',
                'context' => ['http_request_method', 'server_address', 'url_full'],
            ]);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static function (array $row): array {
            $trace = $row['traceId'] !== '' ? Ui::trace($row['traceId']) : null;
            $host = $row['context']['server_address'] ?? '';
            $call = trim(($row['context']['http_request_method'] ?? '').' '.$host.$row['text']);
            $example = $row['context']['url_full'] ?? '';

            return array_filter([
                'call' => Ui::cell($call, $example !== '' ? ['mono' => true, 'sub' => $example] : ['mono' => true]),
                'origin' => Ui::cell($row['origin'] !== '' ? $row['origin'] : '—', $row['function'] !== '' ? ['mono' => true, 'sub' => $row['function']] : ['mono' => true]),
                'traces' => Ui::cell(Format::count($row['traces']), ['raw' => $row['traces'], 'tone' => 'warn']),
                'repeat' => Ui::cell('×'.$row['worstRepeat'], ['raw' => $row['worstRepeat'], 'tone' => 'danger']),
                'trace' => $trace !== null
                    ? Ui::cell(substr($row['traceId'], 0, 8).'…', ['mono' => true, 'link' => $trace])
                    : Ui::cell('—'),
                '_link' => $host !== '' ? Ui::entity('outgoing', $host) : null,
            ], static fn ($v): bool => $v !== null);
        }, array_slice($rows, 0, 50));

        return Ui::table('N+1 outgoing calls', [
            Ui::col('call', 'Call'),
            Ui::col('origin', 'Origin'),
            Ui::num('traces', 'Traces affected'),
            Ui::num('repeat', 'Worst repeat'),
            Ui::num('trace', 'Trace'),
        ], $table, array_filter([
            'subtitle' => 'One endpoint called over and over with different ids, one call after another, in one trace — grouped by call shape, with the line that made it',
            'error' => $error,
            'empty' => 'No N+1 outgoing calls in this period. They need laravel-telemetry 3.6 or later.',
            'note' => 'Fired once per call shape per trace when it crosses the repeat threshold (default 3). Reads only; pagination, retries of the same URL and calls sent together with Http::pool are not counted. Fix with a batch endpoint, or send the calls together with Http::pool.',
        ], static fn ($v): bool => $v !== null));
    }
}
