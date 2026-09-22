<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\QueueThroughput;

/**
 * The throughput card, scoped to one queue.
 */
final class QueueDetailThroughput extends QueueThroughput
{
    use ScopesToQueue;
}
