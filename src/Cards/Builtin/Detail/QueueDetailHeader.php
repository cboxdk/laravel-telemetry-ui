<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of a queue-detail page: the queue name, a link back, and its
 * headline numbers (backlog, oldest job, drain rate, failure rate, workers).
 */
final class QueueDetailHeader extends Card
{
    use ScopesToQueue;

    public function render(): View
    {
        $error = null;
        $pending = $oldest = $perMinute = $failure = $workers = 0.0;

        try {
            $pending = $this->total('sum('.$this->metric('queue_metrics_queue_depth', 'state="pending"').')');
            $oldest = $this->total('max('.$this->metric('queue_metrics_queue_oldest_job_age_seconds').')');
            $perMinute = $this->total('sum('.$this->metric('queue_metrics_queue_throughput_per_min').')');
            $failure = $this->total('max('.$this->metric('queue_metrics_queue_failure_rate_percent').')');
            $workers = $this->total('sum('.$this->metric('queue_metrics_queue_active_workers').')');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->queue === '' ? '(all queues)' : $this->queue,
            'subtitle' => 'Queue detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All queues',
            'error' => $error,
            'stats' => [
                ['label' => 'Pending', 'value' => Format::count($pending), 'tone' => $pending > 0 ? null : 'dim'],
                ['label' => 'Oldest', 'value' => $oldest > 0 ? Format::ms($oldest * 1000) : '—', 'tone' => $oldest >= 60 ? 'warn' : 'dim'],
                ['label' => 'Jobs/min', 'value' => Format::count($perMinute), 'tone' => null],
                ['label' => 'Failure', 'value' => Format::percent($failure / 100), 'tone' => $failure > 0 ? 'danger' : 'dim'],
                ['label' => 'Workers', 'value' => Format::count($workers), 'tone' => null],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return $this->pageUrl('queues');
    }
}
