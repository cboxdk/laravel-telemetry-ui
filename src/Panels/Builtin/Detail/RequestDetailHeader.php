<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a request-detail page: the route, a link back to the list, and
 * its headline numbers (throughput, error rate, latency) over the period.
 */
final class RequestDetailHeader extends Panel
{
    use ScopesToRoute;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_server_request_duration_seconds_count');
        $errors = $this->metric('http_server_request_duration_seconds_count', 'http_response_status_code=~"5.."');
        $sum = $this->metric('http_server_request_duration_seconds_sum');
        $bucket = $this->metric('http_server_request_duration_seconds_bucket');

        $error = null;
        $total = $errCount = $time = 0.0;
        $p95 = null;

        try {
            $total = $this->total($count->increase($p)->sumBy());
            $errCount = $this->total($errors->increase($p)->sumBy());
            $time = $this->total($sum->increase($p)->sumBy());
            $p95value = $this->total($bucket->quantile(0.95, $p));
            $p95 = is_nan($p95value) ? null : $p95value;
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $errRate = $total > 0 ? $errCount / $total : 0.0;

        return Ui::header(
            $this->route === '' ? '(all routes)' : $this->route,
            'Route detail',
            [
                $this->stat('Requests', Format::count($total), null, Ui::explore('requests', ['http.route='.$this->route])),
                $this->stat('Error rate', Format::percent($errRate), $errRate > 0.01 ? 'danger' : 'dim', Ui::explore('requests', ['http.route='.$this->route, 'http.response.status_code>=500'])),
                $this->stat('AVG', $total > 0 ? Format::ms($time / $total * 1000) : '—', 'dim'),
                $this->stat('P95', $p95 !== null ? Format::ms($p95 * 1000) : '—', 'warn', $p95 !== null ? Ui::explore('requests', ['http.route='.$this->route, 'duration>='.(int) round($p95 * 1000).'ms']) : null),
            ],
            array_filter([
                'back' => Ui::page('requests'),
                'backLabel' => '← All requests',
                'error' => $error,
            ], static fn ($v): bool => $v !== null),
        );
    }

    protected function statLinks(): array
    {
        return ['AVG' => Ui::explore('requests', ['http.route='.$this->route])];
    }
}
