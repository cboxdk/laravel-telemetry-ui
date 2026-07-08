<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Database activity over time — the New-Relic-"Databases"-overview chart:
 * queries per minute across the period, with headline tiles for total queries,
 * rolled-back transactions and N+1 (duplicate) queries detected. Straight from
 * the emitter's `db_*` counters, so it's exact and cheap; the per-statement
 * breakdown lives in {@see QueryPerformance} below it.
 */
final class QueryThroughput extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();
        $period = $this->promDuration();

        try {
            $perMinute = $this->metrics()->queryRange(
                $this->metric('db_queries_total')->rate($this->rateWindow())->sumBy()->times(60),
                $start,
                $end,
            );

            $total = $this->total($this->metric('db_queries_total')->increase($period)->sumBy());
            $rolledBack = $this->total($this->metric('db_transactions_rolled_back_total')->increase($period)->sumBy());
            $duplicates = $this->total($this->metric('db_queries_duplicated_total')->increase($period)->sumBy());
            $minutes = max(1.0, $this->rangeSeconds() / 60.0);
        } catch (SourceException $exception) {
            return $this->chartCard('Database throughput', error: $exception->getMessage(), span: 2);
        }

        return $this->chartCard(
            title: 'Database throughput',
            subtitle: 'Queries per minute across the period — the DB load behind the statements below',
            series: $this->toChartSeries($perMinute, 'queries/min'),
            stats: [
                $this->stat('Queries', Format::count($total)),
                $this->stat('Per minute', Format::count($total / $minutes)),
                $this->stat('Rolled back', Format::count($rolledBack), $rolledBack > 0 ? 'danger' : 'dim'),
                $this->stat('N+1 detected', Format::count($duplicates), $duplicates > 0 ? 'warn' : 'dim'),
            ],
            type: 'bar',
            unit: 'q/min',
            span: 2,
        );
    }
}
