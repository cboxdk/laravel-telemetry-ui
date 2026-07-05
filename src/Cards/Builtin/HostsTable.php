<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Every host/server reporting telemetry, with its request volume, error rate
 * and current CPU/memory — the "which boxes am I running on" view. Each row
 * drills into that host's requests.
 */
final class HostsTable extends Card
{
    public function render(): View
    {
        $p = $this->promDuration();
        $count = $this->metric('http_server_request_duration_milliseconds_count');
        $errors = $this->metric('http_server_request_duration_milliseconds_count', 'http_response_status_code=~"5.."');

        /** @var array<string, array{host: string, requests: float, errors: float, cpu: ?float, memory: ?float}> $rows */
        $rows = [];
        $error = null;

        $collect = function (string $query, string $field) use (&$rows): void {
            foreach ($this->metrics()->query($query) as $sample) {
                $host = $sample->labels['host_name'] ?? '';
                if ($host === '') {
                    continue;
                }
                $rows[$host] ??= ['host' => $host, 'requests' => 0.0, 'errors' => 0.0, 'cpu' => null, 'memory' => null];
                $rows[$host][$field] = $sample->value;
            }
        };

        try {
            $collect('sum by (host_name) (increase('.$count.'['.$p.']))', 'requests');
            $collect('sum by (host_name) (increase('.$errors.'['.$p.']))', 'errors');
            $collect('avg by (host_name) ('.$this->metric('system_cpu_utilization_ratio').')', 'cpu');
            $collect('avg by (host_name) ('.$this->metric('system_memory_utilization_ratio', 'state="used"').')', 'memory');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.hosts-table';

        return view($view, ['rows' => array_slice($rows, 0, 100), 'error' => $error]);
    }

    /**
     * Requests from this host — a dimensional filter on the traces page.
     */
    public function tracesUrl(string $host): string
    {
        return $this->pageUrl('traces', [
            'q' => '{ '.$this->traceScope('.host.name = "'.addcslashes($host, '"\\').'"').' }',
        ]);
    }
}
