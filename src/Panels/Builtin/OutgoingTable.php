<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Outgoing HTTP requests per upstream host: volume, errors and latency.
 */
final class OutgoingTable extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    /**
     * @param  list<float>  $spark
     * @return array<string, mixed>
     */
    private static function row(string $host, array $spark = []): array
    {
        return [
            'host' => $host,
            'ok' => 0.0,
            '4xx' => 0.0,
            '5xx' => 0.0,
            'total' => 0.0,
            'failures' => 0.0,
            'time' => 0.0,
            'p95' => null,
            'connects' => 0.0,
            'connect_time' => 0.0,
            'connect_p95' => null,
            'spark' => $spark,
        ];
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_client_request_duration_seconds_count');
        $sum = $this->metric('http_client_request_duration_seconds_sum');
        $bucket = $this->metric('http_client_request_duration_seconds_bucket');
        // See the note in OutgoingActivity: the old
        // `http_client_connection_failures_total` never existed. A call
        // that failed before a response carries `error.type` and no
        // status code.
        $failures = $this->metric('http_client_request_duration_seconds_count', 'error_type!="",http_response_status_code=""');
        $connectBucket = $this->metric('http_client_connection_duration_seconds_bucket');
        $connectSum = $this->metric('http_client_connection_duration_seconds_sum');
        $connectCount = $this->metric('http_client_connection_duration_seconds_count');

        $rows = [];
        $error = null;
        $trends = [];

        try {
            $trends = $this->trendByKey(
                $count->rate($this->rateWindow())->sumBy('server_address')->times(60),
                $start,
                $end,
                fn (array $labels): string => $labels['server_address'] ?? '?',
            );

            foreach ($this->metrics()->query($count->increase($p)->sumBy('server_address', 'http_response_status_code')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                $rows[$host] ??= self::row($host, $trends[$host] ?? []);

                $code = $sample->labels['http_response_status_code'] ?? '';
                $class = match ($code === '' ? '' : $code[0].'xx') {
                    '4xx' => '4xx',
                    '5xx' => '5xx',
                    default => 'ok',
                };

                $rows[$host][$class] += $sample->value;
                $rows[$host]['total'] += $sample->value;
            }

            foreach ($this->metrics()->query($sum->increase($p)->sumBy('server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host])) {
                    // v2 duration histogram is in seconds; ×1000 → ms for the view.
                    $rows[$host]['time'] = $sample->value * 1000;
                }
            }

            foreach ($this->metrics()->query($bucket->quantile(0.95, $p, 'server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host]) && ! is_nan($sample->value)) {
                    $rows[$host]['p95'] = $sample->value * 1000;
                }
            }

            foreach ($this->metrics()->query($failures->increase($p)->sumBy('server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                $rows[$host] ??= self::row($host, $trends[$host] ?? []);
                $rows[$host]['failures'] = $sample->value;
            }

            // How long it took to GET a connection, separately from how
            // long the call took. Same total, opposite fixes: a handshake
            // to a machine three regions away is a provisioning problem,
            // and the far end thinking is theirs. Only connections
            // actually established are in here — a reused one is a setup
            // of zero and would drag every percentile toward it.
            foreach ($this->metrics()->query($connectSum->increase($p)->sumBy('server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host])) {
                    $rows[$host]['connect_time'] = $sample->value * 1000;
                }
            }

            foreach ($this->metrics()->query($connectCount->increase($p)->sumBy('server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host])) {
                    $rows[$host]['connects'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query($connectBucket->quantile(0.95, $p, 'server_address')) as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host]) && ! is_nan($sample->value)) {
                    $rows[$host]['connect_p95'] = $sample->value * 1000;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $table = array_map(static function (array $row): array {
            // The purpose-built detail page for this upstream host.
            $link = Ui::entity('outgoing', $row['host']);

            return [
                'host' => Ui::cell($row['host'], ['mono' => true, 'link' => $link, 'dim' => ['key' => 'server.address', 'value' => $row['host']]]),
                'trend' => Ui::cell(null, ['spark' => $row['spark'] ?? [], 'tone' => $row['5xx'] > 0 || $row['failures'] > 0 ? 'danger' : 'info']),
                'ok' => Ui::cell(Format::count($row['ok']), ['raw' => $row['ok']]),
                '4xx' => Ui::cell(Format::count($row['4xx']), ['raw' => $row['4xx'], 'tone' => $row['4xx'] > 0 ? 'warn' : null]),
                '5xx' => Ui::cell(Format::count($row['5xx']), ['raw' => $row['5xx'], 'tone' => $row['5xx'] > 0 ? 'danger' : null]),
                'failures' => Ui::cell(Format::count($row['failures']), ['raw' => $row['failures'], 'tone' => $row['failures'] > 0 ? 'danger' : null]),
                'total' => Ui::cell(Format::count($row['total']), ['raw' => $row['total']]),
                'avg' => $row['total'] > 0
                    ? Ui::cell(Format::ms($row['time'] / $row['total']), ['raw' => $row['time'] / $row['total']])
                    : Ui::cell('—'),
                'p95' => $row['p95'] !== null ? Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95']]) : Ui::cell('—'),
                'connect' => $row['connects'] > 0
                    ? Ui::cell(Format::ms($row['connect_time'] / $row['connects']), ['raw' => $row['connect_time'] / $row['connects']])
                    : Ui::cell('—'),
                'connect_p95' => $row['connect_p95'] !== null
                    ? Ui::cell(Format::ms($row['connect_p95']), ['raw' => $row['connect_p95']])
                    : Ui::cell('—'),
                // The share of calls that had to open a connection. A
                // client that reuses nothing pays a handshake per
                // request, which is invisible in the total and obvious
                // here.
                'reuse' => $row['total'] > 0
                    ? Ui::cell(Format::percent(max(0.0, 1 - $row['connects'] / $row['total'])), ['raw' => max(0.0, 1 - $row['connects'] / $row['total'])])
                    : Ui::cell('—'),
                '_link' => $link,
            ];
        }, array_slice($rows, 0, 100));

        return Ui::table('Upstream hosts', [
            Ui::col('host', 'Host'),
            Ui::col('trend', 'Trend'),
            Ui::num('ok', '1/2/3XX'),
            Ui::num('4xx', '4XX'),
            Ui::num('5xx', '5XX'),
            Ui::num('failures', 'Conn. failures'),
            Ui::num('total', 'Total'),
            Ui::num('avg', 'AVG'),
            Ui::num('p95', 'P95'),
            Ui::num('connect', 'Connect AVG'),
            Ui::num('connect_p95', 'Connect P95'),
            Ui::num('reuse', 'Reused'),
        ], $table, array_filter([
            'error' => $error,
            'empty' => 'No outgoing requests in this period.',
        ], static fn ($v): bool => $v !== null));
    }
}
