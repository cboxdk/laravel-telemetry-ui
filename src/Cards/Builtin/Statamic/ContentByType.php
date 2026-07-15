<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Builtin\MetricFacetTable;

/**
 * Content changes broken down by type and action (e.g. entry × saved /
 * deleted) — what is actually changing in the CMS.
 */
final class ContentByType extends MetricFacetTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Changes by type & action',
            'metric' => 'statamic_content_changes_total',
            'keys' => ['type' => 'Type', 'action' => 'Action'],
            'valueColumn' => 'Changes',
        ];
    }
}
