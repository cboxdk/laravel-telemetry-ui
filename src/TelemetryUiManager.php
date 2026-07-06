<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Cards\Builtin;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;

/**
 * Registry for dashboard pages, cards and MCP tools. Registration is data-only
 * (class-strings and labels) so packages can contribute from their service
 * providers at zero boot cost.
 *
 * @api Use via the TelemetryUi facade. page()/card()/mcpTool() are the
 *      supported extension points.
 *
 * @phpstan-type PageMeta array{label: string, group: string|null, icon: string|null, detect: string|null, hidden?: bool}
 */
final class TelemetryUiManager
{
    /**
     * Built-in pages. Pages with a "detect" metric pattern only appear when
     * the connected backends contain matching metrics — e.g. the Statamic
     * page lights up for fleets running cboxdk/statamic-telemetry.
     *
     * @var array<string, PageMeta>
     */
    private array $pages = [
        'dashboard' => ['label' => 'Dashboard', 'group' => null, 'icon' => null, 'detect' => null],
        'traces' => ['label' => 'Traces', 'group' => null, 'icon' => null, 'detect' => null],
        'requests' => ['label' => 'Requests', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        // Purpose-built detail pages: routable + rendered, but not in the
        // sidebar nav (reached by drilling into a row).
        'request-detail' => ['label' => 'Request', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'jobs' => ['label' => 'Jobs', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'job-detail' => ['label' => 'Job', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'horizon' => ['label' => 'Horizon', 'group' => 'Activity', 'icon' => null, 'detect' => 'horizon_.*'],
        'commands' => ['label' => 'Commands', 'group' => 'Activity', 'icon' => null, 'detect' => 'commands_.*'],
        'schedule' => ['label' => 'Scheduled Tasks', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exceptions' => ['label' => 'Exceptions', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exception-detail' => ['label' => 'Exception', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'error-detail' => ['label' => 'Issue', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'queries' => ['label' => 'Queries', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'cache' => ['label' => 'Cache', 'group' => 'Activity', 'icon' => null, 'detect' => 'cache_operations.*'],
        'storage' => ['label' => 'Storage', 'group' => 'Activity', 'icon' => null, 'detect' => 'storage_operations.*'],
        'livewire' => ['label' => 'Livewire', 'group' => 'Activity', 'icon' => null, 'detect' => 'livewire_.*'],
        'features' => ['label' => 'Feature Flags', 'group' => 'Activity', 'icon' => null, 'detect' => 'feature_(checks|unknown).*'],
        'reverb' => ['label' => 'Reverb', 'group' => 'Activity', 'icon' => null, 'detect' => 'reverb_.*'],
        'outgoing' => ['label' => 'Outgoing Requests', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'outgoing-detail' => ['label' => 'Host', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'mail' => ['label' => 'Mail & Notifications', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'analytics' => ['label' => 'Analytics', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'frontend' => ['label' => 'Frontend', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'hosts' => ['label' => 'Hosts', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'host-detail' => ['label' => 'Host', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'users' => ['label' => 'Users', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'logs' => ['label' => 'Logs', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'system' => ['label' => 'System', 'group' => 'Monitoring', 'icon' => null, 'detect' => 'system_.*'],

        // The Statamic overlay (cboxdk/statamic-telemetry) gets its own
        // sidebar group; each subpage detects its own metric family, so a
        // site only sees the sections whose signals it actually emits.
        'statamic-cache' => ['label' => 'Static Cache', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_static_cache.*'],
        'statamic-stache' => ['label' => 'Stache', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_stache.*'],
        'statamic-glide' => ['label' => 'Glide', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_glide.*'],
        'statamic-forms' => ['label' => 'Forms', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_forms.*'],
        'statamic-content' => ['label' => 'Content', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_content_changes.*'],
        'statamic-inventory' => ['label' => 'Inventory', 'group' => 'Statamic', 'icon' => null, 'detect' => 'statamic_(entries|assets|users)_count'],
    ];

    /**
     * @var array<string, list<class-string<Card>>>
     */
    private array $cards = [
        'traces' => [Builtin\TraceSearch::class, Builtin\ServiceGraph::class],
        'requests' => [Builtin\RequestsActivity::class, Builtin\RequestDuration::class, Builtin\RateLimits::class, Builtin\RoutesTable::class, Builtin\RequestLog::class],
        'request-detail' => [Builtin\Detail\RequestDetailHeader::class, Builtin\Detail\RequestDetailActivity::class, Builtin\Detail\RequestDetailDuration::class, Builtin\Detail\RequestDetailStatus::class, Builtin\Detail\RequestDetailPaths::class, Builtin\Detail\RequestDetailTraces::class],
        'jobs' => [Builtin\JobsOverview::class, Builtin\QueueLag::class, Builtin\JobsTable::class],
        'job-detail' => [Builtin\Detail\JobDetailHeader::class, Builtin\Detail\JobDetailOutcomes::class, Builtin\Detail\JobDetailTraces::class],
        'commands' => [Builtin\CommandsOverview::class, Builtin\CommandsTable::class],
        'schedule' => [Builtin\ScheduleOverview::class, Builtin\ScheduleTable::class],
        'exceptions' => [Builtin\UnifiedErrors::class, Builtin\ExceptionsOverview::class, Builtin\ExceptionsTable::class],
        'exception-detail' => [Builtin\Detail\ExceptionDetailHeader::class, Builtin\Detail\ExceptionDetailTrend::class, Builtin\Detail\ExceptionDetailTraces::class],
        'error-detail' => [Builtin\Detail\ErrorGroupHeader::class, Builtin\Detail\ErrorGroupTrend::class, Builtin\Detail\ErrorGroupSidebar::class, Builtin\Detail\ErrorGroupTags::class, Builtin\Detail\ErrorGroupDetail::class],
        'queries' => [Builtin\SlowQueries::class, Builtin\DuplicateQueries::class],
        'cache' => [Builtin\CacheOperations::class],
        'storage' => [Builtin\StorageOperations::class],
        'livewire' => [Builtin\LivewireActivity::class, Builtin\LivewireSlow::class],
        'features' => [Builtin\FeatureChecks::class],
        'horizon' => [Builtin\HorizonOverview::class, Builtin\HorizonIncidents::class],
        'reverb' => [Builtin\ReverbConnections::class, Builtin\ReverbMessages::class],
        'outgoing' => [Builtin\OutgoingActivity::class, Builtin\OutgoingTable::class],
        'outgoing-detail' => [Builtin\Detail\OutgoingHostHeader::class, Builtin\Detail\OutgoingHostActivity::class, Builtin\Detail\OutgoingHostTraces::class],
        'mail' => [Builtin\MailOverview::class, Builtin\NotificationsOverview::class],
        'statamic-cache' => [Builtin\StaticCacheOverview::class],
        'statamic-stache' => [Builtin\Statamic\StacheActivity::class],
        'statamic-glide' => [Builtin\Statamic\GlideGenerations::class],
        'statamic-forms' => [Builtin\Statamic\FormsSubmissions::class],
        'statamic-content' => [Builtin\Statamic\ContentChanges::class],
        'statamic-inventory' => [Builtin\Statamic\Inventory::class],
        'analytics' => [Builtin\AnalyticsOverview::class, Builtin\AnalyticsPages::class, Builtin\AnalyticsBreakdown::class],
        'frontend' => [Builtin\WebVitals::class, Builtin\FrontendPages::class, Builtin\FrontendFetches::class],
        'hosts' => [Builtin\HostsTable::class],
        'host-detail' => [Builtin\Detail\HostDetailHeader::class, Builtin\Detail\HostServices::class, Builtin\Detail\HostDetailCpu::class, Builtin\Detail\HostDetailMemory::class, Builtin\Detail\HostDetailNetwork::class, Builtin\Detail\HostDetailFilesystem::class],
        'users' => [Builtin\TrafficByFacet::class],
        'logs' => [Builtin\LogViewer::class],
        'system' => [Builtin\SystemMemory::class, Builtin\SystemCpu::class, Builtin\SystemFilesystem::class, Builtin\SystemNetwork::class],
    ];

    /**
     * The dashboard's default cards live in config (telemetry-ui.cards), not the
     * $cards map above. Fold them into $cards['dashboard'] once — before any
     * card()/setCards()/removeCard()/cards() touches it — so those mutators act
     * on the real, effective list (otherwise removeCard/setCards on 'dashboard'
     * would silently no-op against config-declared cards).
     */
    private bool $dashboardSeeded = false;

    /**
     * Extra MCP tools contributed by apps/packages, appended to the built-in
     * read tools the TelemetryServer already exposes.
     *
     * @var list<class-string<Tool>>
     */
    private array $mcpTools = [];

    /**
     * Per-viewer scope lock (tenancy). Returns the services / environments the
     * current user may see; empty/absent = unrestricted for that dimension.
     *
     * @var (Closure(mixed): array{services?: list<string>, environments?: list<string>})|null
     */
    private ?Closure $scopeResolver = null;

    /**
     * Per-viewer connection-config resolver (multi-tenant hosting).
     *
     * @var (Closure(mixed): array<string, array<string, mixed>>)|null
     */
    private ?Closure $connectionResolver = null;

    public function __construct(private readonly Config $config) {}

    /**
     * Lock the dashboard to a subset of services and/or environments per
     * viewer — a lightweight tenancy control for embedding in an app. The
     * resolver receives the authenticated user and returns the allowed set;
     * the scope switcher only offers it and every query is forced into it.
     *
     * @param  Closure(mixed): array{services?: list<string>, environments?: list<string>}  $resolver
     */
    public function restrictScopeUsing(Closure $resolver): self
    {
        $this->scopeResolver = $resolver;

        return $this;
    }

    /**
     * @return (Closure(mixed): array{services?: list<string>, environments?: list<string>})|null
     */
    public function scopeResolver(): ?Closure
    {
        return $this->scopeResolver;
    }

    /**
     * Resolve backend connection config per viewer (multi-tenant hosting): point
     * each tenant at their own Mimir/Tempo/Loki, or the same backend with a
     * different `X-Scope-OrgID`. The resolver receives the authenticated user
     * and returns a map of connection name → config; anything it omits falls
     * back to the static `telemetry-ui.connections` config. Resolved per request.
     *
     * @param  Closure(mixed): array<string, array<string, mixed>>  $resolver
     */
    public function resolveConnectionsUsing(Closure $resolver): self
    {
        $this->connectionResolver = $resolver;

        return $this;
    }

    /**
     * @return (Closure(mixed): array<string, array<string, mixed>>)|null
     */
    public function connectionResolver(): ?Closure
    {
        return $this->connectionResolver;
    }

    /**
     * Register an MCP tool on the telemetry server. Packages call this from a
     * service provider to expose their own read tool (e.g. an autoscale
     * decision explainer) alongside the built-in metrics/traces/logs tools.
     *
     * @param  class-string<Tool>  $tool
     */
    public function mcpTool(string $tool): self
    {
        $this->mcpTools[] = $tool;

        return $this;
    }

    /**
     * App/package-contributed MCP tools. The TelemetryServer merges these with
     * its built-in tools when the server boots.
     *
     * @return list<class-string<Tool>>
     */
    public function mcpTools(): array
    {
        return array_values(array_unique($this->mcpTools));
    }

    /**
     * Register a page in the sidebar. Pages with the same group are shown
     * together (e.g. "Activity", "Monitoring"). Pass a $detectMetric regex
     * (matched against metric names, e.g. "autoscale_.*") to show the page
     * only when the backends contain matching metrics.
     */
    public function page(
        string $slug,
        string $label,
        ?string $group = null,
        ?string $icon = null,
        ?string $detectMetric = null,
        bool $hidden = false,
    ): self {
        $this->pages[$slug] = ['label' => $label, 'group' => $group, 'icon' => $icon, 'detect' => $detectMetric, 'hidden' => $hidden];

        return $this;
    }

    /**
     * Register a card on a page (appended after any already there).
     *
     * @param  class-string<Card>  $card
     */
    public function card(string $card, string $page = 'dashboard'): self
    {
        if ($page === 'dashboard') {
            $this->seedDashboardCards();
        }

        $this->cards[$page][] = $card;

        return $this;
    }

    /**
     * Replace a page's entire card list — swap the built-in cards for your own
     * (e.g. a branded dashboard). Pass [] to blank the page.
     *
     * @param  list<class-string<Card>>  $cards
     */
    public function setCards(string $page, array $cards): self
    {
        // Replacing the list outright — mark dashboard seeded so cards() won't
        // re-merge the config defaults on top of the caller's chosen set.
        if ($page === 'dashboard') {
            $this->dashboardSeeded = true;
        }

        $this->cards[$page] = array_values($cards);

        return $this;
    }

    /**
     * Remove a card from a page — drop a built-in you don't want.
     *
     * @param  class-string<Card>  $card
     */
    public function removeCard(string $card, string $page = 'dashboard'): self
    {
        if ($page === 'dashboard') {
            $this->seedDashboardCards();
        }

        $this->cards[$page] = array_values(array_filter(
            $this->cards[$page] ?? [],
            static fn (string $registered): bool => $registered !== $card,
        ));

        return $this;
    }

    /**
     * Remove a page entirely (and its cards) — hide a built-in section such as
     * Users or Logs from an embedded/white-labelled install.
     */
    public function removePage(string $slug): self
    {
        unset($this->pages[$slug], $this->cards[$slug]);

        return $this;
    }

    /**
     * @return array<string, PageMeta>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * Pages that should be visible given what the backends contain, within the
     * given PromQL scope (empty = fleet-wide) — so an optional group hides for a
     * selected service that doesn't emit its metrics.
     *
     * @return array<string, PageMeta>
     */
    public function visiblePages(SchemaDetector $detector, string $scope = ''): array
    {
        return array_filter(
            $this->pages,
            static fn (array $meta): bool => $meta['detect'] === null || $detector->hasMetricsMatching($meta['detect'], $scope),
        );
    }

    public function hasPage(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    /**
     * Cards for a page, config-declared dashboard cards first.
     *
     * @return list<class-string<Card>>
     */
    public function cards(string $page = 'dashboard'): array
    {
        if ($page === 'dashboard') {
            $this->seedDashboardCards();
        }

        return array_values(array_unique($this->cards[$page] ?? []));
    }

    /**
     * Fold the config-declared dashboard cards into the runtime registry, once.
     * Config cards come first (matching the prior read-time merge order), then
     * anything a package already registered on the dashboard at runtime.
     */
    private function seedDashboardCards(): void
    {
        if ($this->dashboardSeeded) {
            return;
        }

        $this->dashboardSeeded = true;

        /** @var list<class-string<Card>> $configured */
        $configured = array_values(array_filter((array) $this->config->get('telemetry-ui.cards', []), 'is_string'));

        $this->cards['dashboard'] = [...$configured, ...($this->cards['dashboard'] ?? [])];
    }

    /**
     * All registered cards across pages, for Livewire component registration.
     *
     * @return list<class-string<Card>>
     */
    public function allCards(): array
    {
        $cards = [];

        foreach (array_keys($this->pages) as $page) {
            $cards = [...$cards, ...$this->cards($page)];
        }

        return array_values(array_unique($cards));
    }

    /**
     * The Livewire component alias for a card class.
     *
     * @param  class-string<Card>  $card
     */
    public static function componentName(string $card): string
    {
        return 'telemetry-ui.'.Str::kebab(class_basename($card));
    }
}
