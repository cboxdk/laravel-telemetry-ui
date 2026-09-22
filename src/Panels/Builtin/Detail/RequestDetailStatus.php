<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The next drill-down level for a route: the exact status codes it returns
 * (200/302/404/500/…), not just the 2xx/4xx/5xx classes the overview shows —
 * where the errors actually concentrate.
 */
final class RequestDetailStatus extends Panel
{
    use ScopesToRoute;

    public function data(): array
    {
        $p = $this->promDuration();
        $count = $this->metric('http_server_request_duration_seconds_count');

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

        $table = array_map(static function (array $row) use ($max): array {
            $tone = match ($row['class']) {
                '5' => 'danger',
                '4' => 'warn',
                default => null,
            };

            return [
                'code' => Ui::cell($row['code'], ['mono' => true, 'tone' => $tone, 'dim' => ['key' => 'http.response.status_code', 'value' => $row['code']]]),
                'share' => Ui::cell(null, ['bar' => $max > 0 ? $row['count'] / $max : 0.0, 'tone' => $tone ?? 'dim']),
                'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count']]),
            ];
        }, $rows);

        return Ui::table('Status codes', [
            Ui::col('code', 'Code'),
            Ui::col('share', 'Share'),
            Ui::num('count', 'Count'),
        ], $table, array_filter([
            'subtitle' => 'Exact response codes for this route — where errors concentrate',
            'error' => $error,
            'empty' => 'No requests in this period.',
        ], static fn ($v): bool => $v !== null));
    }
}
