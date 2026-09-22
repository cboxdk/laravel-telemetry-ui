<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Warm-build latency distribution from the Stache warm-duration histogram —
 * the shape behind the single P95 stat on the overview: is every rebuild slow,
 * or is it a thin tail? A list under the thin chart, like the other Statamic
 * pages carry their breakdown.
 */
final class StacheWarmLatency extends Panel
{
    /** @var list<int> */
    private const PERCENTILES = [50, 75, 90, 95, 99];

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $bucket = $this->metric('statamic_stache_warm_duration_milliseconds_bucket');
        $window = $this->promDuration();

        $rows = [];
        $error = null;

        try {
            foreach (self::PERCENTILES as $percentile) {
                $value = $this->total($bucket->quantile($percentile / 100, $window));
                $rows[] = ['percentile' => 'p'.$percentile, 'value' => is_nan($value) ? null : $value];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // An all-null spread means the histogram saw no warms this period —
        // show the empty state instead of a column of dashes.
        if ($error === null && array_filter($rows, static fn (array $row): bool => $row['value'] !== null) === []) {
            $rows = [];
        }

        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                'percentile' => Ui::cell($row['percentile']),
                'value' => $row['value'] === null
                    ? Ui::cell('—', ['mono' => true])
                    : Ui::cell(Format::ms($row['value']), ['raw' => $row['value'], 'mono' => true]),
            ];
        }

        return Ui::table('Warm-build latency', [
            Ui::col('percentile', 'Percentile'),
            Ui::num('value', 'Warm build'),
        ], $table, [
            'subtitle' => 'How long Stache rebuilds take, by percentile — the distribution behind the P95.',
            'span' => 2,
            'error' => $error,
            'empty' => 'No Stache warms in this period.',
        ]);
    }
}
