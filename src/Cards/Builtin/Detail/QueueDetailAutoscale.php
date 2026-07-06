<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\AutoscaleWorkers;

/**
 * The autoscaler's target-vs-active card, scoped to one queue. Renders the
 * clean empty state when the fleet doesn't run the autoscaler.
 */
final class QueueDetailAutoscale extends AutoscaleWorkers
{
    use ScopesToQueue;
}
