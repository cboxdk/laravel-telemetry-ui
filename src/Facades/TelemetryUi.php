<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Facades;

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cbox\TelemetryUi\TelemetryUiManager page(string $slug, string $label, ?string $group = null, ?string $icon = null, ?string $detectMetric = null)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager card(string $card, string $page = 'dashboard')
 * @method static array<string, array{label: string, group: string|null, icon: string|null, detect: string|null}> pages()
 * @method static bool hasPage(string $slug)
 * @method static list<class-string<\Cbox\TelemetryUi\Cards\Card>> cards(string $page = 'dashboard')
 *
 * @see TelemetryUiManager
 */
final class TelemetryUi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelemetryUiManager::class;
    }
}
