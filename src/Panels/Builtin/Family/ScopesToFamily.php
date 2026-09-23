<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Family;

use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * A family panel knows which family it serves from the page it is rendered on
 * — the registry keys families by page slug, so one panel class serves them
 * all.
 */
trait ScopesToFamily
{
    /**
     * @return array{label: string, pattern: string, source: string, dimension: string|null, valueLabel: string}|null
     */
    protected function family(): ?array
    {
        return app(TelemetryUiManager::class)->routeFamilyFor($this->onPage);
    }
}
