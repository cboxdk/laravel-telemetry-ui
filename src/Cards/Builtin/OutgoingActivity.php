<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Outgoing HTTP client requests per host.
 */
final class OutgoingActivity extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $count = $this->metric('http_client_request_duration_milliseconds_count');
        $failures = $this->metric('http_client_connection_failures_total');
        $p = $this->promDuration();

        try {
            $total = $this->total('sum(increase('.$count.'['.$p.']))');
            $failed = $this->total('sum(increase('.$failures.'['.$p.']))');
            $serverErrors = $this->total('sum(increase('.$this->metric('http_client_request_duration_milliseconds_count', 'http_response_status_code=~"5.."').'['.$p.']))');

            $range = $this->metrics()->queryRange(
                'sum by (server_address) (rate('.$count.'['.$this->rateWindow().'])) * 60',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Outgoing requests', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Outgoing requests',
            series: $this->toChartSeries($range, 'server_address'),
            stats: [
                $this->stat('Requests', Format::count($total)),
                $this->stat('5XX', Format::count($serverErrors), $serverErrors > 0 ? 'danger' : 'dim'),
                $this->stat('Conn. failures', Format::count($failed), $failed > 0 ? 'danger' : 'dim'),
            ],
            type: 'area',
            unit: 'req/min',
            span: 2,
        );
    }
}
