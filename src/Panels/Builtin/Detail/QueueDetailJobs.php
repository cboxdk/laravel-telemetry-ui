<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\JobsTable;

/**
 * The per-job table, scoped to one queue — the job classes running on this
 * queue, each linking on to its job-detail page.
 */
final class QueueDetailJobs extends JobsTable
{
    use ScopesToQueue;
}
