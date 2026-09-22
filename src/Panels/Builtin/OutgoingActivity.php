<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Outgoing HTTP client requests per host.
 */
class OutgoingActivity extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $count = $this->metric('http_client_request_duration_seconds_count');
        $failures = $this->metric('http_client_connection_failures_total');
        $p = $this->promDuration();

        try {
            $total = $this->total($count->increase($p)->sumBy());
            $failed = $this->total($failures->increase($p)->sumBy());
            $serverErrors = $this->total($this->metric('http_client_request_duration_seconds_count', 'http_response_status_code=~"5.."')->increase($p)->sumBy());

            $range = $this->metrics()->queryRange(
                $count->rate($this->rateWindow())->sumBy('server_address')->times(60),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Outgoing requests', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Outgoing requests',
            subtitle: 'HTTP calls your app makes to upstream services, per host per minute',
            series: $this->toChartSeries($range, 'server_address'),
            stats: [
                $this->stat('Requests', Format::count($total), null, Ui::entityIndex('outgoing')),
                $this->stat('5XX', Format::count($serverErrors), $serverErrors > 0 ? 'danger' : 'dim', Ui::explore('traces', ['server.address!=', 'http.response.status_code>=500'])),
                $this->stat('Conn. failures', Format::count($failed), $failed > 0 ? 'danger' : 'dim'),
            ],
            type: 'area',
            unit: 'req/min',
            span: 2,
        );
    }

    protected function statLinks(): array
    {
        return ['Conn. failures' => Ui::explore('traces', ['kind=client', 'status=error'])];
    }
}
