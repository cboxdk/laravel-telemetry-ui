<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * The range-vector function applied to a {@see MetricQuery}'s selector before
 * aggregation. `None` is a bare instant selector (a gauge's current value).
 * `CounterIncrease` is the sparse-counter-safe increase
 * (`clamp_min(sel - (sel offset w or sel * 0), 0)`) that counts series born
 * mid-window.
 */
enum MetricFn
{
    case None;
    case Rate;
    case Increase;
    case CounterIncrease;
}
