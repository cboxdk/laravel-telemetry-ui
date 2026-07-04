<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of a request-detail page: the route, a link back to the list, and
 * its headline numbers (throughput, error rate, latency) over the period.
 */
final class RequestDetailHeader extends Card
{
    use ScopesToRoute;

    public function render(): View
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_server_request_duration_milliseconds_count');
        $errors = $this->metric('http_server_request_duration_milliseconds_count', 'http_response_status_code=~"5.."');
        $sum = $this->metric('http_server_request_duration_milliseconds_sum');
        $bucket = $this->metric('http_server_request_duration_milliseconds_bucket');

        $error = null;
        $total = $errCount = $time = 0.0;
        $p95 = null;

        try {
            $total = $this->total('sum(increase('.$count.'['.$p.']))');
            $errCount = $this->total('sum(increase('.$errors.'['.$p.']))');
            $time = $this->total('sum(increase('.$sum.'['.$p.']))');
            $p95value = $this->total('histogram_quantile(0.95, sum by (le) (rate('.$bucket.'['.$p.'])))');
            $p95 = is_nan($p95value) ? null : $p95value;
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $errRate = $total > 0 ? $errCount / $total : 0.0;

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->route === '' ? '(all routes)' : $this->route,
            'subtitle' => 'Route detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All requests',
            'error' => $error,
            'stats' => [
                ['label' => 'Requests', 'value' => Format::count($total), 'tone' => null],
                ['label' => 'Error rate', 'value' => Format::percent($errRate), 'tone' => $errRate > 0.01 ? 'danger' : 'dim'],
                ['label' => 'AVG', 'value' => $total > 0 ? Format::ms($time / $total) : '—', 'tone' => 'dim'],
                ['label' => 'P95', 'value' => $p95 !== null ? Format::ms($p95) : '—', 'tone' => 'warn'],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'requests',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
