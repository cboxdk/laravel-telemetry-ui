<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Per-job outcomes and durations, with drill-down to matching traces.
 */
final class JobsTable extends Card
{
    #[Url(as: 'job_search')]
    public string $search = '';

    public function render(): View
    {
        $p = $this->period()->promDuration();

        $rows = [];

        try {
            foreach (['processed', 'failed', 'released'] as $outcome) {
                $samples = $this->metrics()->query(
                    'sum by (job_name, queue) (increase('.$this->metric('queue_jobs_'.$outcome.'_total').'['.$p.']))',
                );

                foreach ($samples as $sample) {
                    $key = ($sample->labels['job_name'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

                    $rows[$key] ??= [
                        'job' => $sample->labels['job_name'] ?? '?',
                        'queue' => $sample->labels['queue'] ?? '?',
                        'processed' => 0.0, 'failed' => 0.0, 'released' => 0.0,
                        'time' => 0.0, 'count' => 0.0, 'p95' => null,
                    ];

                    $rows[$key][$outcome] += $sample->value;
                }
            }

            $times = $this->metrics()->query(
                'sum by (job_name, queue) (increase('.$this->metric('queue_job_duration_milliseconds_sum').'['.$p.']))',
            );

            $counts = $this->metrics()->query(
                'sum by (job_name, queue) (increase('.$this->metric('queue_job_duration_milliseconds_count').'['.$p.']))',
            );

            $p95s = $this->metrics()->query(
                'histogram_quantile(0.95, sum by (job_name, queue, le) (rate('.$this->metric('queue_job_duration_milliseconds_bucket').'['.$p.'])))',
            );
        } catch (SourceException $exception) {
            return $this->view([], $exception->getMessage());
        }

        foreach ($times as $sample) {
            $key = ($sample->labels['job_name'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

            if (isset($rows[$key])) {
                $rows[$key]['time'] = $sample->value;
            }
        }

        foreach ($counts as $sample) {
            $key = ($sample->labels['job_name'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

            if (isset($rows[$key])) {
                $rows[$key]['count'] = $sample->value;
            }
        }

        foreach ($p95s as $sample) {
            $key = ($sample->labels['job_name'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

            if (isset($rows[$key]) && ! is_nan($sample->value)) {
                $rows[$key]['p95'] = $sample->value;
            }
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => $row['processed'] + $row['failed'] + $row['released'] >= 0.5);

        if ($this->search !== '') {
            $rows = array_filter($rows, fn (array $row): bool => stripos($row['job'].' '.$row['queue'], $this->search) !== false);
        }

        usort($rows, static fn (array $a, array $b): int => ($b['processed'] + $b['failed'] + $b['released']) <=> ($a['processed'] + $a['failed'] + $a['released']));

        return $this->view(array_slice($rows, 0, 100), null);
    }

    public function tracesUrl(string $job): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('span.laravel.job.class = "'.addcslashes($job, '"\\').'"').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function view(array $rows, ?string $error): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.jobs-table';

        return view($view, ['rows' => $rows, 'error' => $error]);
    }
}
