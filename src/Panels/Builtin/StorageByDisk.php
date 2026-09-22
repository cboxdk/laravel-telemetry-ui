<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

/**
 * Filesystem operations per disk and operation — which disk does the work
 * (and whether it's reads, writes or deletes).
 */
final class StorageByDisk extends MetricFacetTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Storage by disk',
            'metric' => 'storage_operations_total',
            'keys' => ['disk' => 'Disk', 'operation' => 'Operation'],
            'valueColumn' => 'Operations',
        ];
    }
}
