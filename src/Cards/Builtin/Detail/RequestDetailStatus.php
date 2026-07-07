<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The next drill-down level for a route: the exact status codes it returns
 * (200/302/404/500/…), not just the 2xx/4xx/5xx classes the overview shows —
 * where the errors actually concentrate.
 */
final class RequestDetailStatus extends Card
{
    use ScopesToRoute;

    public function render(): View
    {
        $p = $this->promDuration();
        $count = $this->metric('http_server_request_duration_milliseconds_count');

        $rows = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                $count->increase($p)->sumBy('http_response_status_code'),
            );

            foreach ($samples as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $code = $sample->labels['http_response_status_code'] ?? '?';
                $rows[] = ['code' => $code, 'count' => $sample->value, 'class' => $code[0] ?? '?'];
            }

            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $max = $rows === [] ? 0.0 : max(array_column($rows, 'count'));

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-status';

        return view($view, [
            'rows' => $rows,
            'max' => $max,
            'error' => $error,
            'formatCount' => static fn (float $v): string => Format::count($v),
        ]);
    }
}
