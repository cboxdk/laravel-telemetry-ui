<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of a job-detail page: the job class, a link back, and its
 * headline numbers (processed, failed, average duration).
 */
final class JobDetailHeader extends Card
{
    use ScopesToJob;

    public function render(): View
    {
        $p = $this->promDuration();
        $processed = $this->metric('queue_jobs_processed_total');
        $failed = $this->metric('queue_jobs_failed_total');
        $durSum = $this->metric('queue_job_duration_milliseconds_sum');
        $durCount = $this->metric('queue_job_duration_milliseconds_count');

        $error = null;
        $proc = $fail = $time = $cnt = 0.0;

        try {
            $proc = $this->total('sum(increase('.$processed.'['.$p.']))');
            $fail = $this->total('sum(increase('.$failed.'['.$p.']))');
            $time = $this->total('sum(increase('.$durSum.'['.$p.']))');
            $cnt = $this->total('sum(increase('.$durCount.'['.$p.']))');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->job === '' ? '(all jobs)' : $this->job,
            'subtitle' => 'Job detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All jobs',
            'error' => $error,
            'stats' => [
                ['label' => 'Processed', 'value' => Format::count($proc), 'tone' => null],
                ['label' => 'Failed', 'value' => Format::count($fail), 'tone' => $fail > 0 ? 'danger' : 'dim'],
                ['label' => 'AVG', 'value' => $cnt > 0 ? Format::ms($time / $cnt) : '—', 'tone' => 'dim'],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'jobs',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
