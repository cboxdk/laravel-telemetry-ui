<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\QueueThroughput;

/**
 * The throughput card, scoped to one queue.
 */
final class QueueDetailThroughput extends QueueThroughput
{
    use ScopesToQueue;
}
