<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Filesystem/Storage activity — `storage.operations{disk,operation}` from
 * laravel-telemetry's Flysystem decorator (local, S3, any driver).
 */
final class StorageOperations extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('storage_operations_total');
        $p = $this->promDuration();

        try {
            $byDisk = $this->metrics()->query($metric->increase($p)->sumBy('disk'));

            $range = $this->metrics()->queryRange(
                $metric->rate($this->rateWindow())->sumBy('operation')->times(60),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Storage operations', error: $exception->getMessage(), span: 2);
        }

        $total = 0.0;
        $stats = [];

        foreach ($byDisk as $sample) {
            $total += $sample->value;
        }

        $stats[] = $this->stat('Operations', Format::count($total));

        // One tile per disk (bounded — disks are operator-configured).
        foreach (array_slice($byDisk, 0, 4) as $sample) {
            $stats[] = $this->stat($sample->labels['disk'] ?? '?', Format::count($sample->value), 'dim');
        }

        return $this->chartCard(
            title: 'Storage operations',
            subtitle: 'Disk operations per minute by type (put, get, delete, …), across every Flysystem disk',
            series: $this->toChartSeries($range, 'operation'),
            stats: $stats,
            type: 'area',
            unit: 'ops/min',
            span: 2,
        );
    }
}
