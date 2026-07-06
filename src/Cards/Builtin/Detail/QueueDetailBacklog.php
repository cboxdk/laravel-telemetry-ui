<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\QueueBacklog;

/**
 * The backlog-by-state card, scoped to one queue.
 */
final class QueueDetailBacklog extends QueueBacklog
{
    use ScopesToQueue;
}
