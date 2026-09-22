<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Per-job outcomes and durations, with drill-down to matching traces.
 */
class JobsTable extends Panel
{
    #[Param('job_search')]
    public string $search = '';

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $rows = [];
        $trends = [];

        try {
            $trends = $this->trendByKey(
                $this->metric('queue_jobs_processed_total')->rate($this->rateWindow())->sumBy('job_name', 'queue')->times(60),
                $start,
                $end,
                fn (array $labels): string => ($labels['job_name'] ?? '?').'|'.($labels['queue'] ?? '?'),
            );

            foreach (['processed', 'failed', 'released'] as $outcome) {
                $samples = $this->metrics()->query(
                    $this->metric('queue_jobs_'.$outcome.'_total')->increase($p)->sumBy('job_name', 'queue'),
                );

                foreach ($samples as $sample) {
                    $key = ($sample->labels['job_name'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

                    $rows[$key] ??= [
                        'job' => $sample->labels['job_name'] ?? '?',
                        'queue' => $sample->labels['queue'] ?? '?',
                        'processed' => 0.0, 'failed' => 0.0, 'released' => 0.0,
                        'time' => 0.0, 'count' => 0.0, 'p95' => null,
                        'spark' => $trends[$key] ?? [],
                    ];

                    $rows[$key][$outcome] += $sample->value;
                }
            }

            $times = $this->metrics()->query(
                $this->metric('queue_job_duration_milliseconds_sum')->increase($p)->sumBy('job_name', 'queue'),
            );

            $counts = $this->metrics()->query(
                $this->metric('queue_job_duration_milliseconds_count')->increase($p)->sumBy('job_name', 'queue'),
            );

            $p95s = $this->metrics()->query(
                $this->metric('queue_job_duration_milliseconds_bucket')->quantile(0.95, $p, 'job_name', 'queue'),
            );
        } catch (SourceException $exception) {
            return $this->payload([], $exception->getMessage());
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

        return $this->payload(array_values(array_slice($rows, 0, 100)), null);
    }

    /**
     * @param  list<array{job: string, queue: string, processed: float, failed: float, released: float, time: float, count: float, p95: ?float, spark: list<float>}>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?string $error): array
    {
        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                '_link' => Ui::entity('job', $row['job']),
                'job' => Ui::cell($row['job'], [
                    'link' => Ui::entity('job', $row['job']),
                    'dim' => ['key' => 'laravel.job.class', 'value' => $row['job']],
                ]),
                'queue' => Ui::cell($row['queue'], [
                    'badge' => $row['queue'],
                    'dim' => ['key' => 'messaging.destination.name', 'value' => $row['queue']],
                ]),
                'trend' => Ui::cell(null, [
                    'spark' => $row['spark'],
                    'tone' => $row['failed'] > 0 ? 'danger' : ($row['released'] > 0 ? 'warn' : 'ok'),
                ]),
                'processed' => Ui::cell(Format::count($row['processed']), ['raw' => $row['processed'], 'mono' => true]),
                'released' => Ui::cell(Format::count($row['released']), ['raw' => $row['released'], 'mono' => true, 'tone' => $row['released'] > 0 ? 'warn' : null]),
                'failed' => Ui::cell(Format::count($row['failed']), ['raw' => $row['failed'], 'mono' => true, 'tone' => $row['failed'] > 0 ? 'danger' : null]),
                'avg' => $row['count'] > 0
                    ? Ui::cell(Format::ms($row['time'] / $row['count']), ['raw' => $row['time'] / $row['count'], 'mono' => true])
                    : Ui::cell('—', ['mono' => true]),
                'p95' => $row['p95'] !== null
                    ? Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95'], 'mono' => true])
                    : Ui::cell('—', ['mono' => true]),
            ];
        }

        return Ui::table('Jobs', [
            Ui::col('job', 'Job'),
            Ui::col('queue', 'Queue'),
            Ui::col('trend', 'Trend'),
            Ui::num('processed', 'Processed'),
            Ui::num('released', 'Released'),
            Ui::num('failed', 'Failed'),
            Ui::num('avg', 'AVG'),
            Ui::num('p95', 'P95'),
        ], $table, [
            'subtitle' => 'Per-job outcomes and duration — click a job for its traces',
            'span' => 2,
            'error' => $error,
            'empty' => $this->search !== '' ? 'No jobs match this search.' : 'No jobs in this period.',
            'controls' => [Ui::search('job_search', 'Search', $this->search, 'Search jobs…')],
        ]);
    }
}
