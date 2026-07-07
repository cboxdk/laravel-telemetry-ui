<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Livewire\Attributes\Url;

/**
 * Scopes a card to a single queue job (the `?job=` on the job-detail page).
 */
trait ScopesToJob
{
    #[Url(as: 'job')]
    public string $job = '';

    protected function scopeMatchers(): string
    {
        return $this->job === '' ? '' : 'job_name="'.addcslashes($this->job, '"\\').'"';
    }

    /**
     * @return list<TraceCondition>
     */
    protected function jobTraceConditions(): array
    {
        return [TraceCondition::eq('span.laravel.job.class', $this->job)];
    }
}
