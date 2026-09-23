<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Facades;

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Facade;

/**
 * Public registry facade — the supported way for apps and packages to
 * contribute pages, panels, dimensions and MCP tools to the dashboard.
 *
 * @method static \Cbox\TelemetryUi\TelemetryUiManager page(string $slug, string $label, ?string $group = null, ?string $icon = null, ?string $detectMetric = null, bool $hidden = false)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager navLink(string $key, string $label, string $url, ?string $icon = null)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager removeNavLink(string $key)
 * @method static list<\Cbox\TelemetryUi\Support\NavLink> navLinks()
 * @method static \Cbox\TelemetryUi\TelemetryUiManager connection(string $value, string $label, string $url)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager currentConnection(?string $value)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager removeConnection(string $value)
 * @method static list<\Cbox\TelemetryUi\Support\ConnectionOption> connections()
 * @method static string selectedConnection()
 * @method static \Cbox\TelemetryUi\Support\ViewState viewState()
 * @method static \Cbox\TelemetryUi\TelemetryUiManager panel(string $panel, string $page = 'dashboard')
 * @method static \Cbox\TelemetryUi\TelemetryUiManager setPanels(string $page, list<class-string<\Cbox\TelemetryUi\Panels\Panel>> $panels)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager removePanel(string $panel, string $page = 'dashboard')
 * @method static \Cbox\TelemetryUi\TelemetryUiManager dimension(string $key, ?string $label = null, ?string $group = null, \Closure|string|null $link = null, ?string $entity = null, ?string $scope = null, ?list<string> $signals = null, ?string $format = null, ?string $plural = null, ?\Closure $resolve = null, ?string $from = null, ?string $pattern = null)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager routeFamily(string $slug, string $label, string $pattern, ?string $dimension = null, ?string $group = 'Activity', ?string $icon = null, ?string $valueLabel = null, ?string $source = null)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager metricPanel(string $id, string $title, string $metric, string $page, string $type = 'line', ?string $unit = null, ?string $by = null, bool $rate = false, ?float $quantile = null, ?string $stat = null, ?string $subtitle = null, int $span = 1, array<string, string> $where = [])
 * @method static \Cbox\TelemetryUi\TelemetryUiManager resolve(string $key, string|\Closure $using, string|\Closure $display = 'name', ?string $column = null)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager removeDimension(string $key)
 * @method static \Cbox\TelemetryUi\Dimensions\Dimensions dimensions()
 * @method static \Cbox\TelemetryUi\TelemetryUiManager entityPage(string $entity, string $page, string $param)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager removePage(string $slug)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager mcpTool(string $tool)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager restrictScopeUsing(\Closure $resolver)
 * @method static \Cbox\TelemetryUi\TelemetryUiManager resolveConnectionsUsing(\Closure $resolver, bool $needsViewer = true)
 * @method static bool connectionResolverNeedsViewer()
 * @method static array<string, array{label: string, group: string|null, icon: string|null, detect: string|null, hidden?: bool}> pages()
 * @method static bool hasPage(string $slug)
 * @method static list<class-string<\Cbox\TelemetryUi\Panels\Panel>> panels(string $page = 'dashboard')
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
