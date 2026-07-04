<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use DateTimeInterface;

/**
 * A PromQL-capable metrics backend (Prometheus, Mimir, ...).
 *
 * @api Implement to add a metrics driver; cards depend only on this contract.
 */
interface MetricsSource
{
    /**
     * Evaluate an instant query.
     *
     * @return list<Sample>
     */
    public function query(string $promql, ?DateTimeInterface $at = null): array;

    /**
     * Evaluate a range query. When $step is null a sensible step is derived
     * from the range so charts get roughly 250 points.
     *
     * @return list<TimeSeries>
     */
    public function queryRange(
        string $promql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $step = null,
    ): array;

    /**
     * List the known values of a label, optionally restricted to series
     * matching a selector (e.g. label_values(http_..., service_name)).
     *
     * @return list<string>
     */
    public function labelValues(
        string $label,
        ?string $match = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array;
}
