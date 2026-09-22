<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Panels\Builtin\MetricFacetTable;

/**
 * Form submissions broken down by form handle — which forms receive traffic.
 */
final class FormsByForm extends MetricFacetTable
{
    protected function spec(): array
    {
        return [
            'title' => 'Submissions by form',
            'metric' => 'statamic_forms_submissions_total',
            'keys' => ['form' => 'Form'],
            'valueColumn' => 'Submissions',
        ];
    }
}
