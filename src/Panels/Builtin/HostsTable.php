<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Format;

/**
 * Every host/server reporting telemetry, with its request volume, error rate
 * and current CPU/memory — the "which boxes am I running on" view. Each row
 * drills into that host's requests.
 */
final class HostsTable extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $p = $this->promDuration();
        $count = $this->metric('http_server_request_duration_seconds_count');
        $errors = $this->metric('http_server_request_duration_seconds_count', 'http_response_status_code=~"5.."');

        $error = null;

        /** @var array<string, array<string, float>> $values field → host → value */
        $values = ['requests' => [], 'errors' => [], 'cpu' => [], 'memory' => []];

        $collect = function (MetricQuery $query): array {
            $byHost = [];

            foreach ($this->metrics()->query($query) as $sample) {
                $host = $sample->labels['host_name'] ?? '';

                if ($host !== '') {
                    $byHost[$host] = $sample->value;
                }
            }

            return $byHost;
        };

        // Backends spell dimensionless gauges differently (`_ratio` per the
        // Prometheus convention, bare on telemetryd) — match both.
        $cpu = $this->metric('', '__name__=~"system_cpu_utilization(_ratio)?"');
        $memory = $this->metric('', '__name__=~"system_memory_utilization(_ratio)?",state="used"');
        $note = null;

        try {
            $values['requests'] = $collect($count->increase($p)->sumBy('host_name'));
            $values['errors'] = $collect($errors->increase($p)->sumBy('host_name'));
            $values['cpu'] = $collect($cpu->avgBy('host_name'));
            $values['memory'] = $collect($memory->avgBy('host_name'));

            // Metrics without a host label (the host is only a resource
            // attribute on spans): list the hosts traces report, and when
            // there is exactly one, the unlabelled metrics are its metrics.
            if (array_merge(...array_values($values)) === []) {
                [$start, $end] = $this->range();
                $hosts = array_values(array_filter($this->traces()->tagValues('resource.host.name', null, $start, $end, 50), static fn (string $h): bool => $h !== ''));

                if (count($hosts) === 1) {
                    $host = $hosts[0];
                    $values = [
                        'requests' => [$host => $this->total($count->increase($p)->sumBy())],
                        'errors' => [$host => $this->total($errors->increase($p)->sumBy())],
                        'cpu' => [$host => $this->total($cpu->avgBy())],
                        'memory' => [$host => $this->total($memory->avgBy())],
                    ];
                    $note = 'Metrics carry no host label; this single host is inferred from its traces.';
                } else {
                    foreach ($hosts as $host) {
                        $values['requests'][$host] = 0.0;
                    }

                    $note = $hosts !== [] ? 'Metrics carry no host label, so per-host numbers are unavailable — hosts are listed from traces.' : null;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // Every host seen by any of the queries gets a row.
        $rows = [];

        foreach (array_keys(array_merge(...array_values($values))) as $host) {
            $host = (string) $host;
            $rows[] = [
                'host' => $host,
                'requests' => $values['requests'][$host] ?? 0.0,
                'errors' => $values['errors'][$host] ?? 0.0,
                'cpu' => $values['cpu'][$host] ?? null,
                'memory' => $values['memory'][$host] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);

        $table = [];

        foreach (array_slice($rows, 0, 100) as $row) {
            $host = $row['host'];

            $table[] = [
                '_link' => Ui::entity('host', $host),
                'host' => Ui::cell($host, [
                    'link' => Ui::entity('host', $host),
                    'dim' => ['key' => 'host.name', 'value' => $host],
                ]),
                // Requests from this host — a dimensional filter on the traces page.
                'requests' => Ui::cell(Format::count($row['requests']), [
                    'raw' => $row['requests'],
                    'mono' => true,
                    'link' => Ui::page('traces', ['q' => $this->tracesQuery($host)]),
                ]),
                'errors' => Ui::cell(Format::count($row['errors']), ['raw' => $row['errors'], 'mono' => true, 'tone' => $row['errors'] > 0 ? 'danger' : null]),
                'cpu' => Ui::cell($row['cpu'] !== null && ! is_nan($row['cpu']) ? Format::percent($row['cpu']) : '—', ['raw' => $row['cpu'], 'mono' => true]),
                'memory' => Ui::cell($row['memory'] !== null ? Format::percent($row['memory']) : '—', [
                    'raw' => $row['memory'],
                    'mono' => true,
                    'tone' => ($row['memory'] ?? 0) > 0.9 ? 'warn' : null,
                ]),
            ];
        }

        return Ui::table('Hosts', [
            Ui::col('host', 'Host'),
            Ui::num('requests', 'Requests'),
            Ui::num('errors', '5XX'),
            Ui::num('cpu', 'CPU'),
            Ui::num('memory', 'Memory'),
        ], $table, [
            'subtitle' => 'Every host reporting telemetry — request volume, errors, CPU, memory. Click a host for its detail page.',
            'span' => 2,
            'error' => $error,
            'note' => $note,
            'empty' => 'No hosts reporting in this period.',
        ]);
    }

    /**
     * The TraceQL for requests from this host.
     */
    private function tracesQuery(string $host): string
    {
        return '{ '.$this->traceScope('.host.name = "'.addcslashes($host, '"\\').'"').' }';
    }
}
