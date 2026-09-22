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

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_client_request_duration_seconds_count');
        $sum = $this->metric('http_client_request_duration_seconds_sum');
        $bucket = $this->metric('http_client_request_duration_seconds_bucket');
        $failures = $this->metric('http_client_connection_failures_total');

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

                $rows[$host] ??= ['host' => $host, 'ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0, 'total' => 0.0, 'failures' => 0.0, 'time' => 0.0, 'p95' => null, 'spark' => $trends[$host] ?? []];

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

                $rows[$host] ??= ['host' => $host, 'ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0, 'total' => 0.0, 'failures' => 0.0, 'time' => 0.0, 'p95' => null];
                $rows[$host]['failures'] = $sample->value;
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
        ], $table, array_filter([
            'error' => $error,
            'empty' => 'No outgoing requests in this period.',
        ], static fn ($v): bool => $v !== null));
    }
}
