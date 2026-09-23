<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Family;

use Cbox\TelemetryUi\Dimensions\Derivation;
use Cbox\TelemetryUi\Panels\Builtin\RequestsActivity;

/**
 * The throughput chart of a route family: the requests panel narrowed to the
 * layer's own routes.
 */
final class FamilyActivity extends RequestsActivity
{
    use ScopesToFamily;

    protected ?string $drillPage = null;

    protected function activityTitle(): string
    {
        return $this->family()['label'] ?? 'Requests';
    }

    protected function scopeMatchers(): string
    {
        $family = $this->family();

        if ($family === null) {
            return '';
        }

        $derived = new Derivation($family['source'], $family['pattern']);

        return sprintf('%s=~"%s"', str_replace(['.', '-'], '_', $family['source']), $derived->regex());
    }
}
