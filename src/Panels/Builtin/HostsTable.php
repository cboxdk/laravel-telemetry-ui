<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\Support\ScopeLabels;

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

        $collect = function (MetricQuery $query, ?string $hostLabel = null): array {
            $byHost = [];
            $hostLabel ??= ScopeLabels::metrics('host');

            foreach ($this->metrics()->query($query) as $sample) {
                $host = $sample->labels[$hostLabel] ?? '';

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
            $values['requests'] = $collect($count->increase($p)->sumBy(ScopeLabels::metrics('host')));
            $values['errors'] = $collect($errors->increase($p)->sumBy(ScopeLabels::metrics('host')));
            $values['cpu'] = $this->hostColumn('cpu', $collect) ?? $collect($cpu->avgBy(ScopeLabels::metrics('host')));
            $values['memory'] = $this->hostColumn('memory', $collect) ?? $collect($memory->avgBy(ScopeLabels::metrics('host')));

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
                    'link' => Ui::explore('requests', ['host.name='.$host]),
                ]),
                'errors' => Ui::cell(Format::count($row['errors']), ['raw' => $row['errors'], 'mono' => true, 'tone' => $row['errors'] > 0 ? 'danger' : null]),
                'cpu' => self::utilisation($row['cpu']),
                'memory' => self::utilisation($row['memory']),
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
     * A 0–1 utilisation as a percentage with a bar; amber from 80%, red from 95%.
     *
     * @return array<string, mixed>
     */
    private static function utilisation(?float $ratio): array
    {
        if ($ratio === null || is_nan($ratio)) {
            return Ui::cell('—', ['raw' => -1, 'mono' => true]);
        }

        return Ui::cell(Format::percent($ratio), [
            'raw' => $ratio,
            'mono' => true,
            'bar' => max(0.0, min(1.0, $ratio)),
            'tone' => $ratio >= 0.95 ? 'danger' : ($ratio >= 0.8 ? 'warn' : null),
        ]);
    }

    /**
     * A CPU or memory column from the host's own exporter, when one is
     * configured (`telemetry-ui.hosts.<column>`): a PromQL query with one
     * series per host, labelled by `telemetry-ui.hosts.host_label` (default:
     * the metrics host label). `{environment}` expands to the scope's
     * environments as an RE2 alternation for an `=~` matcher — `.+` when
     * nothing narrows it, a matches-nothing value when locked to none — so a
     * query written for the whole fleet still stays inside the viewer's lock.
     * Null when the column isn't configured, so the built-in query runs.
     *
     * @param  callable(MetricQuery, ?string): array<string, float>  $collect
     * @return array<string, float>|null
     */
    private function hostColumn(string $column, callable $collect): ?array
    {
        $template = config("telemetry-ui.hosts.$column");

        if (! is_string($template) || $template === '') {
            return null;
        }

        $label = config('telemetry-ui.hosts.host_label');

        return $collect(
            MetricQuery::raw(str_replace('{environment}', $this->environmentPattern(), $template)),
            is_string($label) && $label !== '' ? $label : null,
        );
    }
}
