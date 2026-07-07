<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of an outgoing-host detail page: the upstream host, a link back,
 * and its headline numbers (requests, failures, latency).
 */
final class OutgoingHostHeader extends Card
{
    use ScopesToHost;

    public function render(): View
    {
        $p = $this->promDuration();
        $count = $this->metric('http_client_request_duration_milliseconds_count');
        $errors = $this->metric('http_client_request_duration_milliseconds_count', 'http_response_status_code=~"5.."');
        $failures = $this->metric('http_client_connection_failures_total');
        $sum = $this->metric('http_client_request_duration_milliseconds_sum');

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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->host === '' ? '(all hosts)' : $this->host,
            'subtitle' => 'Outgoing host detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All hosts',
            'error' => $error,
            'stats' => [
                ['label' => 'Requests', 'value' => Format::count($total), 'tone' => null],
                ['label' => 'Errors', 'value' => Format::count($err), 'tone' => $err > 0 ? 'danger' : 'dim'],
                ['label' => 'Failures', 'value' => Format::count($fail), 'tone' => $fail > 0 ? 'danger' : 'dim'],
                ['label' => 'AVG', 'value' => $total > 0 ? Format::ms($time / $total) : '—', 'tone' => $total > 0 && $bad > 0 ? 'warn' : 'dim'],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return $this->pageUrl('outgoing');
    }
}
