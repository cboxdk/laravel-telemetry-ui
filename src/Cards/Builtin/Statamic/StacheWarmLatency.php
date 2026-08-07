<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Warm-build latency distribution from the Stache warm-duration histogram —
 * the shape behind the single P95 stat on the overview: is every rebuild slow,
 * or is it a thin tail? A list under the thin chart, like the other Statamic
 * pages carry their breakdown.
 */
final class StacheWarmLatency extends Card
{
    /** @var list<int> */
    private const PERCENTILES = [50, 75, 90, 95, 99];

    public function render(): View
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.statamic-stache-latency';

        return view($view, ['rows' => $rows, 'error' => $error]);
    }
}
