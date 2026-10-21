<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

/**
 * Each check against a feature nobody defined — a typo, a flag removed but
 * still asked for — with the request or job that asked (laravel-telemetry's
 * `feature.unknown` events, once per feature per trace).
 */
final class FeatureUnknownLog extends TelemetryEventLog
{
    protected function prefix(): string
    {
        return 'flags';
    }

    protected function pattern(): string
    {
        return 'feature\.unknown';
    }

    protected function title(): string
    {
        return 'Unknown features checked';
    }

    protected function subtitle(): string
    {
        return 'Each check against a feature with no definition, newest first: likely a typo or a stale flag';
    }

    protected function empty(): string
    {
        return 'No checks against undefined features in this period.';
    }

    protected function tone(string $event): string
    {
        return 'warn';
    }
}
