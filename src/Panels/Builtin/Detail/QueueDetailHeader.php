<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a queue-detail page: the queue name, a link back, and its
 * headline numbers (backlog, oldest job, drain rate, failure rate, workers).
 */
final class QueueDetailHeader extends Panel
{
    use ScopesToQueue;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $error = null;
        $pending = $oldest = $perMinute = $failure = $workers = 0.0;

        try {
            $pending = $this->total($this->metric('queue_metrics_queue_depth', 'state="pending"')->sumBy());
            $oldest = $this->total($this->metric('queue_metrics_queue_oldest_job_age_seconds')->maxBy());
            $perMinute = $this->total($this->metric('queue_metrics_queue_throughput_per_min')->sumBy());
            $failure = $this->total($this->metric('queue_metrics_queue_failure_rate_percent')->maxBy());
            $workers = $this->total($this->metric('queue_metrics_queue_active_workers')->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::header($this->queue === '' ? '(all queues)' : $this->queue, 'Queue detail', [
            ['label' => 'Pending', 'value' => Format::count($pending), 'tone' => $pending > 0 ? null : 'dim'],
            ['label' => 'Oldest', 'value' => $oldest > 0 ? Format::ms($oldest * 1000) : '—', 'tone' => $oldest >= 60 ? 'warn' : 'dim'],
            ['label' => 'Jobs/min', 'value' => Format::count($perMinute), 'tone' => null],
            ['label' => 'Failure', 'value' => Format::percent($failure / 100), 'tone' => $failure > 0 ? 'danger' : 'dim'],
            ['label' => 'Workers', 'value' => Format::count($workers), 'tone' => null],
        ], [
            'back' => [...Ui::page('queues'), 'label' => '← All queues'],
            'error' => $error,
            'span' => 2,
        ]);
    }
}
