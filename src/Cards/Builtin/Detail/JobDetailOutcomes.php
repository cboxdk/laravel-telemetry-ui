<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;

/**
 * The job outcomes card (processed / released / failed), scoped to one job.
 */
final class JobDetailOutcomes extends JobsOverview
{
    use ScopesToJob;
}
