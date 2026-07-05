<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Facades;

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Facade;

/**
 * Public registry facade — the supported way for apps and packages to
 * contribute pages, cards and MCP tools to the dashboard.
 *
 * @method static \Cbox\TelemetryUi\TelemetryUiManager page(string $slug, string $label, ?string $group = null, ?string $icon = null, ?string $detectMetric = null, bool $hidden = false)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager card(string $card, string $page = 'dashboard')
 * @method static \Cbox\TelemetryUi\TelemetryUiManager mcpTool(string $tool)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager restrictScopeUsing(\Closure $resolver)
 * @method static array<string, array{label: string, group: string|null, icon: string|null, detect: string|null, hidden?: bool}> pages()
 * @method static bool hasPage(string $slug)
 * @method static list<class-string<\Cbox\TelemetryUi\Cards\Card>> cards(string $page = 'dashboard')
 * @method static list<class-string<\Laravel\Mcp\Server\Tool>> mcpTools()
 *
 * @see TelemetryUiManager
 *
 * @api
 */
final class TelemetryUi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelemetryUiManager::class;
    }
}
