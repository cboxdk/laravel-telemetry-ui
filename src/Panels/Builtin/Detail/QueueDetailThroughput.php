<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\QueueThroughput;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The throughput card, scoped to one queue.
 */
final class QueueDetailThroughput extends QueueThroughput
{
    use ScopesToQueue;

    protected function statLinks(): array
    {
        return ['Now' => Ui::explore('traces', ['messaging.destination.name='.$this->queue])];
    }
}
