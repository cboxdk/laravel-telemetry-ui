<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Per-queue table: current backlog, oldest-job age, drain rate, failure rate
 * and attached workers, with a backlog trend sparkline per row.
 */
final class QueuesTable extends Panel
{
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

        $rows = [];

        try {
            $trends = $this->trendByKey(
                $this->metric('queue_metrics_queue_depth', 'state="pending"')->sumBy('connection', 'queue'),
                $start,
                $end,
                fn (array $labels): string => ($labels['connection'] ?? '?').'|'.($labels['queue'] ?? '?'),
            );

            $columns = [
                'pending' => $this->metric('queue_metrics_queue_depth', 'state="pending"')->sumBy('connection', 'queue'),
                'oldest' => $this->metric('queue_metrics_queue_oldest_job_age_seconds')->maxBy('connection', 'queue'),
                'per_minute' => $this->metric('queue_metrics_queue_throughput_per_min')->sumBy('connection', 'queue'),
                'failure' => $this->metric('queue_metrics_queue_failure_rate_percent')->maxBy('connection', 'queue'),
                'workers' => $this->metric('queue_metrics_queue_active_workers')->sumBy('connection', 'queue'),
            ];

            foreach ($columns as $column => $promql) {
                foreach ($this->metrics()->query($promql) as $sample) {
                    $key = ($sample->labels['connection'] ?? '?').'|'.($sample->labels['queue'] ?? '?');

                    $rows[$key] ??= [
                        'connection' => $sample->labels['connection'] ?? '?',
                        'queue' => $sample->labels['queue'] ?? '?',
                        'pending' => 0.0,
                        'oldest' => 0.0,
                        'per_minute' => 0.0,
                        'failure' => 0.0,
                        'workers' => 0.0,
                        'spark' => $trends[$key] ?? [],
                    ];

                    $rows[$key][$column] = $sample->value;
                }
            }
        } catch (SourceException $exception) {
            return $this->payload([], $exception->getMessage());
        }

        usort($rows, static fn (array $a, array $b): int => $b['pending'] <=> $a['pending']);

        return $this->payload($rows, null);
    }

    /**
     * @param  list<array{connection: string, queue: string, pending: float, oldest: float, per_minute: float, failure: float, workers: float, spark: list<float>}>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?string $error): array
    {
        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                '_link' => Ui::entity('queue', $row['queue']),
                'queue' => Ui::cell($row['queue'], [
                    'link' => Ui::entity('queue', $row['queue']),
                    'dim' => ['key' => 'messaging.destination.name', 'value' => $row['queue']],
                ]),
                'connection' => Ui::cell($row['connection'], ['badge' => $row['connection']]),
                'trend' => Ui::cell(null, ['spark' => $row['spark'], 'tone' => $row['pending'] > 0 ? 'info' : 'dim']),
                'pending' => Ui::cell(Format::count($row['pending']), ['raw' => $row['pending'], 'mono' => true]),
                'oldest' => Ui::cell(
                    $row['oldest'] > 0 ? Format::ms($row['oldest'] * 1000) : '—',
                    ['raw' => $row['oldest'], 'mono' => true, 'tone' => $row['oldest'] >= 60 ? 'warn' : null],
                ),
                'per_minute' => Ui::cell(Format::count($row['per_minute']), ['raw' => $row['per_minute'], 'mono' => true]),
                'failure' => Ui::cell(
                    Format::percent($row['failure'] / 100),
                    ['raw' => $row['failure'], 'mono' => true, 'tone' => $row['failure'] > 0 ? 'danger' : null],
                ),
                'workers' => Ui::cell(Format::count($row['workers']), ['raw' => $row['workers'], 'mono' => true]),
            ];
        }

        return Ui::table('Queues', [
            Ui::col('queue', 'Queue'),
            Ui::col('connection', 'Connection'),
            Ui::col('trend', 'Backlog trend'),
            Ui::num('pending', 'Pending'),
            Ui::num('oldest', 'Oldest'),
            Ui::num('per_minute', 'Jobs/min'),
            Ui::num('failure', 'Failure'),
            Ui::num('workers', 'Workers'),
        ], $table, [
            'subtitle' => 'Per-queue backlog, drain rate, failure rate and attached workers — click a queue for its detail',
            'span' => 2,
            'error' => $error,
            'empty' => 'No queues reporting.',
        ]);
    }
}
