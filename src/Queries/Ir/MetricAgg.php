<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * The aggregation applied over a {@see MetricQuery} (optionally `by` a set of
 * labels). The string value is the PromQL operator. `None` leaves the inner
 * expression un-aggregated.
 */
enum MetricAgg: string
{
    case None = '';
    case Sum = 'sum';
    case Avg = 'avg';
    case Max = 'max';
    case Min = 'min';
    case Count = 'count';
}
