<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Outgoing HTTP requests per upstream host: volume, errors and latency.
 */
final class OutgoingTable extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_client_request_duration_milliseconds_count');
        $sum = $this->metric('http_client_request_duration_milliseconds_sum');
        $bucket = $this->metric('http_client_request_duration_milliseconds_bucket');
        $failures = $this->metric('http_client_connection_failures_total');

        $rows = [];
        $error = null;
        $trends = [];

        try {
            $trends = $this->trendByKey(
                'sum by (server_address) (rate('.$count.'['.$this->rateWindow().'])) * 60',
                $start,
                $end,
                fn (array $labels): string => $labels['server_address'] ?? '?',
            );

            foreach ($this->metrics()->query('sum by (server_address, class) (label_replace(increase('.$count.'['.$p.']), "class", "${1}xx", "http_response_status_code", "([0-9]).."))') as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                $rows[$host] ??= ['host' => $host, 'ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0, 'total' => 0.0, 'failures' => 0.0, 'time' => 0.0, 'p95' => null, 'spark' => $trends[$host] ?? []];

                $class = match ($sample->labels['class'] ?? '') {
                    '4xx' => '4xx',
                    '5xx' => '5xx',
                    default => 'ok',
                };

                $rows[$host][$class] += $sample->value;
                $rows[$host]['total'] += $sample->value;
            }

            foreach ($this->metrics()->query('sum by (server_address) (increase('.$sum.'['.$p.']))') as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host])) {
                    $rows[$host]['time'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query('histogram_quantile(0.95, sum by (server_address, le) (rate('.$bucket.'['.$p.'])))') as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                if (isset($rows[$host]) && ! is_nan($sample->value)) {
                    $rows[$host]['p95'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query('sum by (server_address) (increase('.$failures.'['.$p.']))') as $sample) {
                $host = $sample->labels['server_address'] ?? '?';

                $rows[$host] ??= ['host' => $host, 'ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0, 'total' => 0.0, 'failures' => 0.0, 'time' => 0.0, 'p95' => null];
                $rows[$host]['failures'] = $sample->value;
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.outgoing-table';

        return view($view, ['rows' => array_slice($rows, 0, 100), 'error' => $error]);
    }

    /**
     * The traces-page URL pre-filtered to outgoing calls to this host — the
     * standard OTel `server.address` on client spans.
     */
    public function tracesUrl(string $host): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('span.server.address = "'.addcslashes($host, '"\\').'" && kind = client').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
