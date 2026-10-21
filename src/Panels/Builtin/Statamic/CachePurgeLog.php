<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Panels\Builtin\TelemetryEventLog;

/**
 * Each static-cache purge — what was invalidated and when — from
 * cboxdk/statamic-telemetry.
 */
final class CachePurgeLog extends TelemetryEventLog
{
    protected function prefix(): string
    {
        return 'purge';
    }

    protected function pattern(): string
    {
        return 'statamic\.cache\.purge';
    }

    protected function title(): string
    {
        return 'Cache purges';
    }

    protected function subtitle(): string
    {
        return 'Each purge, newest first: what was invalidated';
    }

    protected function empty(): string
    {
        return 'No cache purges in this period.';
    }
}
