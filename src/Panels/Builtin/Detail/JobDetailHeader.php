<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a job-detail page: the job class, a link back, and its
 * headline numbers (processed, failed, average duration).
 */
final class JobDetailHeader extends Panel
{
    use ScopesToJob;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $p = $this->promDuration();
        $processed = $this->metric('queue_jobs_processed_total');
        $failed = $this->metric('queue_jobs_failed_total');
        $durSum = $this->metric('queue_job_duration_milliseconds_sum');
        $durCount = $this->metric('queue_job_duration_milliseconds_count');

        $error = null;
        $proc = $fail = $time = $cnt = 0.0;

        try {
            $proc = $this->total($processed->increase($p)->sumBy());
            $fail = $this->total($failed->increase($p)->sumBy());
            $time = $this->total($durSum->increase($p)->sumBy());
            $cnt = $this->total($durCount->increase($p)->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::header($this->job === '' ? '(all jobs)' : $this->job, 'Job detail', [
            ['label' => 'Processed', 'value' => Format::count($proc), 'tone' => null],
            ['label' => 'Failed', 'value' => Format::count($fail), 'tone' => $fail > 0 ? 'danger' : 'dim'],
            ['label' => 'AVG', 'value' => $cnt > 0 ? Format::ms($time / $cnt) : '—', 'tone' => 'dim'],
        ], [
            'back' => [...Ui::page('jobs'), 'label' => '← All jobs'],
            'error' => $error,
            'span' => 2,
        ]);
    }

    protected function statLinks(): array
    {
        return [
            'Processed' => Ui::explore('traces', ['laravel.job.class='.$this->job]),
            'Failed' => Ui::explore('traces', ['laravel.job.class='.$this->job, 'status=error']),
            'AVG' => Ui::explore('traces', ['laravel.job.class='.$this->job]),
        ];
    }
}
