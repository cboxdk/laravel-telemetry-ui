<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

/**
 * Each autoscaler decision and incident — scaling actions, SLA breaches and
 * recoveries, fuse trips, leader changes — from cboxdk/laravel-queue-autoscale.
 */
final class AutoscaleEventLog extends TelemetryEventLog
{
    protected function prefix(): string
    {
        return 'autoscale';
    }

    protected function pattern(): string
    {
        return 'queue_autoscale\.[a-z_.]+';
    }

    protected function title(): string
    {
        return 'Autoscaler events';
    }

    protected function subtitle(): string
    {
        return 'Each decision and incident, newest first: what it did and why';
    }

    protected function empty(): string
    {
        return 'No autoscaler events in this period.';
    }
}
