<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

/**
 * Each Horizon incident: a queue waiting past its threshold, a process out
 * of memory.
 */
final class HorizonEventLog extends TelemetryEventLog
{
    protected function prefix(): string
    {
        return 'horizon';
    }

    protected function pattern(): string
    {
        return 'horizon\.[a-z_.]+';
    }

    protected function title(): string
    {
        return 'Horizon incidents';
    }

    protected function subtitle(): string
    {
        return 'Each long wait and out-of-memory process, newest first';
    }

    protected function empty(): string
    {
        return 'No Horizon incidents in this period.';
    }
}
