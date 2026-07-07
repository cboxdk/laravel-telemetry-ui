<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Per-queue table: current backlog, oldest-job age, drain rate, failure rate
 * and attached workers, with a backlog trend sparkline per row.
 */
final class QueuesTable extends Card
{
    public function render(): View
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
            return $this->view([], $exception->getMessage());
        }

        usort($rows, static fn (array $a, array $b): int => $b['pending'] <=> $a['pending']);

        return $this->view($rows, null);
    }

    public function detailUrl(string $queue): string
    {
        return $this->pageUrl('queue-detail', ['queue' => $queue]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function view(array $rows, ?string $error): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.queues-table';

        return view($view, ['rows' => $rows, 'error' => $error]);
    }
}
