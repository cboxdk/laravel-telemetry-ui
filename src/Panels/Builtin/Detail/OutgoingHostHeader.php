<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of an outgoing-host detail page: the upstream host, a link back,
 * and its headline numbers (requests, failures, latency).
 */
final class OutgoingHostHeader extends Panel
{
    use ScopesToHost;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $p = $this->promDuration();
        $count = $this->metric('http_client_request_duration_seconds_count');
        $errors = $this->metric('http_client_request_duration_seconds_count', 'http_response_status_code=~"5.."');
        $failures = $this->metric('http_client_connection_failures_total');
        $sum = $this->metric('http_client_request_duration_seconds_sum');

        $error = null;
        $total = $err = $fail = $time = 0.0;

        try {
            $total = $this->total($count->increase($p)->sumBy());
            $err = $this->total($errors->increase($p)->sumBy());
            $fail = $this->total($failures->increase($p)->sumBy());
            $time = $this->total($sum->increase($p)->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $bad = $err + $fail;

        return Ui::header(
            $this->host === '' ? '(all hosts)' : $this->host,
            'Outgoing host detail',
            [
                $this->stat('Requests', Format::count($total), null, Ui::explore('traces', ['server.address='.$this->host])),
                $this->stat('Errors', Format::count($err), $err > 0 ? 'danger' : 'dim', Ui::explore('traces', ['server.address='.$this->host, 'http.response.status_code>=500'])),
                $this->stat('Failures', Format::count($fail), $fail > 0 ? 'danger' : 'dim'),
                $this->stat('AVG', $total > 0 ? Format::ms($time / $total * 1000) : '—', $total > 0 && $bad > 0 ? 'warn' : 'dim'),
            ],
            array_filter([
                'back' => Ui::page('outgoing'),
                'backLabel' => '← All hosts',
                'error' => $error,
            ], static fn ($v): bool => $v !== null),
        );
    }

    protected function statLinks(): array
    {
        return [
            'Failures' => Ui::explore('traces', ['server.address='.$this->host, 'status=error']),
            'AVG' => Ui::explore('traces', ['server.address='.$this->host]),
        ];
    }
}
