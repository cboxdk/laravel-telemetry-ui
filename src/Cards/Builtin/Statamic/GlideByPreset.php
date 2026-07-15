<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Builtin\MetricFacetTable;

/**
 * Glide image generations broken down by preset — which presets drive the
 * on-demand image pipeline.
 */
final class GlideByPreset extends MetricFacetTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Generations by preset',
            'metric' => 'statamic_glide_generations_total',
            'keys' => ['preset' => 'Preset'],
            'valueColumn' => 'Images',
        ];
    }
}
