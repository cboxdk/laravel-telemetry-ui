<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Livewire\Attributes\Url;

/**
 * Scopes a card to a single queue (the `?queue=` on the queue-detail page).
 * The `queue` label is shared by queue_metrics_*, queue_autoscale_* and
 * laravel-telemetry's own queue_jobs_* families, so one matcher scopes all
 * three.
 */
trait ScopesToQueue
{
    #[Url(as: 'queue')]
    public string $queue = '';

    protected function scopeMatchers(): string
    {
        return $this->queue === '' ? '' : 'queue="'.addcslashes($this->queue, '"\\').'"';
    }
}
