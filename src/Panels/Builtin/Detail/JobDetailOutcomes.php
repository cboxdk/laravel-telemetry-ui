<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The job outcomes card (processed / released / failed), scoped to one job.
 */
final class JobDetailOutcomes extends JobsOverview
{
    use ScopesToJob;

    protected function statLinks(): array
    {
        return [
            'Processed' => Ui::explore('traces', ['laravel.job.class='.$this->job]),
            'Released' => Ui::explore('traces', ['laravel.job.class='.$this->job]),
            'Failed' => Ui::explore('traces', ['laravel.job.class='.$this->job, 'status=error']),
        ];
    }
}
