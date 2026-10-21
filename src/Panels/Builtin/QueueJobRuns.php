<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Builtin\Detail\ScopesToQueue;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

/**
 * The runs on one queue, on the queue-detail page.
 */
final class QueueJobRuns extends JobRunLog
{
    use ScopesToQueue;

    protected function title(): string
    {
        return 'Runs on this queue';
    }

    protected function narrow(): array
    {
        return $this->queue === '' ? [] : [TraceCondition::eq('span.messaging.destination.name', $this->queue)];
    }
}
