<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Dimensions\Derivation;
use Cbox\TelemetryUi\Dimensions\Dimension;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Panels\Builtin;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\ConnectionOption;
use Cbox\TelemetryUi\Support\NavLink;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ViewState;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Tool;

/**
 * Registry for dashboard pages, panels, dimensions and MCP tools. Registration
 * is data-only (class-strings, labels, closures) so packages can contribute
 * from their service providers at zero boot cost.
 *
 * @api Use via the TelemetryUi facade. page()/panel()/dimension()/mcpTool()
 *      are the supported extension points.
 *
 * @phpstan-type PageMeta array{label: string, group: string|null, icon: string|null, detect: string|null, hidden?: bool, parent?: string}
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
        'request-detail' => ['label' => 'Request', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'requests'],
        'jobs' => ['label' => 'Jobs', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'job-detail' => ['label' => 'Job', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'jobs'],
        // Queue infrastructure (cboxdk/laravel-queue-metrics) and the
        // autoscaler (cboxdk/laravel-queue-autoscale) — separate metric
        // families, so each page lights up independently.
        'queues' => ['label' => 'Queues', 'group' => 'Queues', 'icon' => null, 'detect' => 'queue_metrics_.*'],
        'queue-detail' => ['label' => 'Queue', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'queues'],
        'autoscale' => ['label' => 'Autoscale', 'group' => 'Queues', 'icon' => null, 'detect' => 'queue_autoscale_.*'],
        'horizon' => ['label' => 'Horizon', 'group' => 'Queues', 'icon' => null, 'detect' => 'horizon_.*'],
        'commands' => ['label' => 'Commands', 'group' => 'Activity', 'icon' => null, 'detect' => 'commands_.*'],
        'schedule' => ['label' => 'Scheduled Tasks', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exceptions' => ['label' => 'Exceptions', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exception-detail' => ['label' => 'Exception', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'exceptions'],
        'error-detail' => ['label' => 'Issue', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'exceptions'],
        'queries' => ['label' => 'Queries', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'query-detail' => ['label' => 'Query', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'queries'],
        'cache' => ['label' => 'Cache', 'group' => 'Activity', 'icon' => null, 'detect' => 'cache_operations.*'],
        'storage' => ['label' => 'Storage', 'group' => 'Activity', 'icon' => null, 'detect' => 'storage_operations.*'],
        'livewire' => ['label' => 'Livewire', 'group' => 'Activity', 'icon' => null, 'detect' => 'livewire_.*'],
        'features' => ['label' => 'Feature Flags', 'group' => 'Activity', 'icon' => null, 'detect' => 'feature_(checks|unknown).*'],
        'reverb' => ['label' => 'Reverb', 'group' => 'Activity', 'icon' => null, 'detect' => 'reverb_.*'],
        'outgoing' => ['label' => 'Outgoing Requests', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'outgoing-detail' => ['label' => 'Host', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'outgoing'],
        'mail' => ['label' => 'Mail & Notifications', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'analytics' => ['label' => 'Analytics', 'group' => 'Frontend', 'icon' => null, 'detect' => null],
        'page-detail' => ['label' => 'Page', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'analytics'],
        'frontend' => ['label' => 'Web Vitals', 'group' => 'Frontend', 'icon' => null, 'detect' => null],
        'hosts' => ['label' => 'Hosts', 'group' => 'Infrastructure', 'icon' => null, 'detect' => null],
        'host-detail' => ['label' => 'Host', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true, 'parent' => 'hosts'],
        'users' => ['label' => 'Users', 'group' => 'Frontend', 'icon' => null, 'detect' => null],
        'logs' => ['label' => 'Logs', 'group' => 'Infrastructure', 'icon' => null, 'detect' => null],
        'system' => ['label' => 'System', 'group' => 'Infrastructure', 'icon' => null, 'detect' => 'system_.*'],

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
     * @var array<string, list<class-string<Panel>>>
     */
    private array $panels = [
        'traces' => [Builtin\TraceSearch::class, Builtin\ServiceGraph::class],
        'requests' => [Builtin\RequestsActivity::class, Builtin\RequestDuration::class, Builtin\RequestLatencyHeatmap::class, Builtin\RateLimits::class, Builtin\RoutesTable::class, Builtin\RequestLog::class],
        'request-detail' => [Builtin\Detail\RequestDetailHeader::class, Builtin\Detail\RequestDetailActivity::class, Builtin\Detail\RequestDetailDuration::class, Builtin\Detail\RequestDetailStatus::class, Builtin\Detail\RequestDetailPaths::class, Builtin\Detail\RequestDetailTraces::class],
        'jobs' => [Builtin\JobsOverview::class, Builtin\QueueLag::class, Builtin\JobsTable::class],
        'job-detail' => [Builtin\Detail\JobDetailHeader::class, Builtin\Detail\JobDetailOutcomes::class, Builtin\Detail\JobDetailTraces::class],
        'queues' => [Builtin\QueueBacklog::class, Builtin\QueueThroughput::class, Builtin\QueueOldestJob::class, Builtin\QueueWorkers::class, Builtin\QueuesTable::class],
        'queue-detail' => [Builtin\Detail\QueueDetailHeader::class, Builtin\Detail\QueueDetailBacklog::class, Builtin\Detail\QueueDetailThroughput::class, Builtin\Detail\QueueDetailAutoscale::class, Builtin\Detail\QueueDetailJobs::class],
        'autoscale' => [Builtin\AutoscaleWorkers::class, Builtin\AutoscaleActions::class, Builtin\AutoscaleSla::class, Builtin\AutoscaleCluster::class],
        'commands' => [Builtin\CommandsOverview::class, Builtin\CommandsTable::class],
        'schedule' => [Builtin\ScheduleOverview::class, Builtin\ScheduleTable::class],
        'exceptions' => [Builtin\UnifiedErrors::class, Builtin\ExceptionsOverview::class, Builtin\ExceptionsTable::class],
        'exception-detail' => [Builtin\Detail\ExceptionDetailHeader::class, Builtin\Detail\ExceptionDetailTrend::class, Builtin\Detail\ExceptionDetailTraces::class],
        'error-detail' => [Builtin\Detail\ErrorGroupHeader::class, Builtin\Detail\ErrorGroupTrend::class, Builtin\Detail\ErrorGroupSidebar::class, Builtin\Detail\ErrorGroupTags::class, Builtin\Detail\ErrorGroupDetail::class],
        'queries' => [Builtin\QueryThroughput::class, Builtin\QueryPerformance::class, Builtin\SlowQueries::class, Builtin\DuplicateQueries::class],
        'query-detail' => [Builtin\Detail\QueryDetail::class],
        'cache' => [Builtin\CacheOperations::class, Builtin\CacheByStore::class],
        'storage' => [Builtin\StorageOperations::class, Builtin\StorageByDisk::class],
        'livewire' => [Builtin\LivewireActivity::class, Builtin\LivewireSlow::class, Builtin\LivewireComponents::class, Builtin\LivewireRequestLog::class],
        'features' => [Builtin\FeatureChecks::class],
        'horizon' => [Builtin\HorizonOverview::class, Builtin\HorizonIncidents::class],
        'reverb' => [Builtin\ReverbConnections::class, Builtin\ReverbMessages::class],
        'outgoing' => [Builtin\OutgoingActivity::class, Builtin\OutgoingTable::class],
        'outgoing-detail' => [Builtin\Detail\OutgoingHostHeader::class, Builtin\Detail\OutgoingHostActivity::class, Builtin\Detail\OutgoingHostTraces::class],
        'mail' => [Builtin\MailOverview::class, Builtin\NotificationsOverview::class],
        'statamic-cache' => [Builtin\StaticCacheOverview::class, Builtin\Statamic\StaticCacheBreakdown::class],
        'statamic-stache' => [Builtin\Statamic\StacheActivity::class, Builtin\Statamic\StacheWarmLatency::class],
        'statamic-glide' => [Builtin\Statamic\GlideGenerations::class, Builtin\Statamic\GlideByPreset::class],
        'statamic-forms' => [Builtin\Statamic\FormsSubmissions::class, Builtin\Statamic\FormsByForm::class],
        'statamic-content' => [Builtin\Statamic\ContentChanges::class, Builtin\Statamic\ContentByType::class],
        'statamic-inventory' => [Builtin\Statamic\Inventory::class],
        'analytics' => [Builtin\AnalyticsOverview::class, Builtin\AnalyticsPages::class, Builtin\AnalyticsBreakdown::class, Builtin\AnalyticsCampaigns::class],
        'page-detail' => [Builtin\Detail\PageDetailHeader::class, Builtin\Detail\PageDetailTraffic::class, Builtin\Detail\PageDetailPerformance::class, Builtin\Detail\PageDetailTraces::class, Builtin\Detail\PageDetailErrors::class],
        'frontend' => [Builtin\WebVitals::class, Builtin\FrontendPages::class, Builtin\FrontendFetches::class],
        'hosts' => [Builtin\HostsTable::class],
        'host-detail' => [Builtin\Detail\HostDetailHeader::class, Builtin\Detail\HostServices::class, Builtin\Detail\HostDetailCpu::class, Builtin\Detail\HostDetailMemory::class, Builtin\Detail\HostDetailNetwork::class, Builtin\Detail\HostDetailFilesystem::class],
        'users' => [Builtin\TrafficByFacet::class],
        'logs' => [Builtin\LogViewer::class],
        'system' => [Builtin\SystemMemory::class, Builtin\SystemCpu::class, Builtin\SystemFilesystem::class, Builtin\SystemNetwork::class],
    ];

    /**
     * The dashboard's default panels live in config (telemetry-ui.panels), not
     * the $panels map above. Fold them into $panels['dashboard'] once — before
     * any panel()/setPanels()/removePanel()/panels() touches it — so those
     * mutators act on the real, effective list.
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
     * Links back OUT to the host application, shown at the foot of the rail.
     *
     * @var array<string, NavLink>
     */
    private array $navLinks = [];

    /**
     * Backend profiles the host offers in the header's connection switcher.
     *
     * @var array<string, ConnectionOption>
     */
    private array $connections = [];

    /**
     * The value of the currently-selected connection, or null when the host has
     * not said — in which case the switcher selects nothing.
     */
    private ?string $currentConnection = null;

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

    /**
     * Whether the connection resolver needs an authenticated viewer to mean
     * anything. True for multi-tenant hosting (the default); false for hosts
     * with no auth at all, where the connection is a property of the process
     * rather than of a user (e.g. a desktop client bound to one profile).
     */
    private bool $connectionResolverNeedsViewer = true;

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
     * By default the resolver is only consulted for an authenticated viewer —
     * a tenant resolver dereferences the user, so calling it at boot or for a
     * guest would fatal. Pass `needsViewer: false` when the connection does not
     * depend on who is looking (an unauthenticated single-user host, such as a
     * desktop client bound to one connection profile); the resolver is then
     * consulted always and receives null as its viewer.
     *
     * @param  Closure(mixed): array<string, array<string, mixed>>  $resolver
     */
    public function resolveConnectionsUsing(Closure $resolver, bool $needsViewer = true): self
    {
        $this->connectionResolver = $resolver;
        $this->connectionResolverNeedsViewer = $needsViewer;

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
     * Whether {@see resolveConnectionsUsing()} requires an authenticated viewer
     * before it may be consulted.
     */
    public function connectionResolverNeedsViewer(): bool
    {
        return $this->connectionResolverNeedsViewer;
    }

    /**
     * The reader's current view state — the time window, the auto-refresh
     * interval and the service/environment scope actually on screen, whether
     * they came from the URL or from what the reader last chose.
     *
     * A host that draws its own chrome around the dashboard reads it here
     * (`TelemetryUi::viewState()->range()`), and moves it with
     * {@see ViewState::put()}. Listen for {@see ViewStateChanged} to be told
     * when the reader moves it instead.
     */
    public function viewState(): ViewState
    {
        return app(ViewState::class);
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
     * Register a link out of the dashboard, shown at the foot of the icon rail.
     *
     * Unlike a page, this points wherever you like — the host's own settings,
     * a connection switcher, its home screen. Intended for hosts that mount the
     * dashboard as the whole UI, where its chrome is the only navigation the
     * reader has. Registering the same $key twice replaces the earlier link.
     *
     * $icon is one of: back, home, settings, connection, server, database,
     * user. Anything else draws the generic link glyph.
     */
    public function navLink(string $key, string $label, string $url, ?string $icon = null): self
    {
        $this->navLinks[$key] = new NavLink($key, $label, $url, $icon);

        return $this;
    }

    public function removeNavLink(string $key): self
    {
        unset($this->navLinks[$key]);

        return $this;
    }

    /**
     * @return list<NavLink>
     */
    public function navLinks(): array
    {
        return array_values($this->navLinks);
    }

    /**
     * Offer the host's backend profiles in the dashboard header as a native
     * `<select>`: picking one navigates to $url, which is the host's own route
     * and does whatever switching means over there.
     *
     * This is the companion to {@see resolveConnectionsUsing()}. That hook says
     * which backend the dashboard reads from; this one lets the reader change
     * it without leaving the dashboard for a host screen and coming back.
     * Registering the same $value twice replaces the earlier entry.
     *
     * $label and $url are escaped like any other Blade output — as with
     * {@see NavLink()}, a host string never reaches an unescaped sink. That is
     * also why there is no icon or markup parameter here.
     */
    public function connection(string $value, string $label, string $url): self
    {
        $this->connections[$value] = new ConnectionOption($value, $label, $url);

        return $this;
    }

    /**
     * Mark which registered connection is the one currently being read. The
     * switcher shows it as selected; an unknown or unset value selects nothing,
     * so the control never claims to be on a profile it isn't.
     */
    public function currentConnection(?string $value): self
    {
        $this->currentConnection = $value;

        return $this;
    }

    public function removeConnection(string $value): self
    {
        unset($this->connections[$value]);

        return $this;
    }

    /**
     * @return list<ConnectionOption>
     */
    public function connections(): array
    {
        return array_values($this->connections);
    }

    /**
     * The selected connection's value, or '' when the host registered none or
     * named one that isn't in the list.
     */
    public function selectedConnection(): string
    {
        return $this->currentConnection !== null && isset($this->connections[$this->currentConnection])
            ? $this->currentConnection
            : '';
    }

    /**
     * Register a panel on a page (appended after any already there).
     *
     * @param  class-string<Panel>  $panel
     */
    public function panel(string $panel, string $page = 'dashboard'): self
    {
        if ($page === 'dashboard') {
            $this->seedDashboardPanels();
        }

        $this->panels[$page][] = $panel;

        return $this;
    }

    /**
     * Replace a page's entire panel list. Pass [] to blank the page.
     *
     * @param  list<class-string<Panel>>  $panels
     */
    public function setPanels(string $page, array $panels): self
    {
        if ($page === 'dashboard') {
            $this->dashboardSeeded = true;
        }

        $this->panels[$page] = array_values($panels);

        return $this;
    }

    /**
     * Remove a panel from a page — drop a built-in you don't want.
     *
     * @param  class-string<Panel>  $panel
     */
    public function removePanel(string $panel, string $page = 'dashboard'): self
    {
        if ($page === 'dashboard') {
            $this->seedDashboardPanels();
        }

        $this->panels[$page] = array_values(array_filter(
            $this->panels[$page] ?? [],
            static fn (string $registered): bool => $registered !== $panel,
        ));

        return $this;
    }

    /**
     * Remove a page entirely (and its panels) — hide a built-in section such as
     * Users or Logs from an embedded/white-labelled install.
     */
    public function removePage(string $slug): self
    {
        unset($this->pages[$slug], $this->panels[$slug]);

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
     * selected service that doesn't emit its metrics. Every registered pattern
     * is handed to the detector together (one backend round trip).
     *
     * @return array<string, PageMeta>
     */
    public function visiblePages(SchemaDetector $detector, string $scope = ''): array
    {
        $patterns = [];

        foreach ($this->pages as $meta) {
            if ($meta['detect'] !== null) {
                $patterns[] = $meta['detect'];
            }
        }

        $detected = $detector->detect(array_values(array_unique($patterns)), $scope);

        return array_filter(
            $this->pages,
            static fn (array $meta): bool => $meta['detect'] === null || ($detected[$meta['detect']] ?? true),
        );
    }

    public function hasPage(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    /**
     * Panels for a page, config-declared dashboard panels first.
     *
     * @return list<class-string<Panel>>
     */
    public function panels(string $page = 'dashboard'): array
    {
        if ($page === 'dashboard') {
            $this->seedDashboardPanels();
        }

        return array_values(array_unique($this->panels[$page] ?? []));
    }

    /**
     * Fold the config-declared dashboard panels into the runtime registry, once.
     */
    private function seedDashboardPanels(): void
    {
        if ($this->dashboardSeeded) {
            return;
        }

        $this->dashboardSeeded = true;

        /** @var list<class-string<Panel>> $configured */
        $configured = array_values(array_filter((array) $this->config->get('telemetry-ui.panels', []), 'is_string'));

        $this->panels['dashboard'] = [...$configured, ...($this->panels['dashboard'] ?? [])];
    }

    /**
     * All registered panels across pages.
     *
     * @return list<class-string<Panel>>
     */
    public function allPanels(): array
    {
        $panels = [];

        foreach (array_keys($this->pages) as $page) {
            $panels = [...$panels, ...$this->panels($page)];
        }

        return array_values(array_unique($panels));
    }

    /**
     * The panel class registered under an id (`routes-table`), or null.
     *
     * @return class-string<Panel>|null
     */
    public function findPanel(string $id): ?string
    {
        foreach ($this->allPanels() as $panel) {
            if ($panel::id() === $id) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The page (if any) a panel is registered on — used for the per-page gate.
     *
     * @param  class-string<Panel>  $panel
     * @return list<string>
     */
    public function pagesFor(string $panel): array
    {
        $pages = [];

        foreach (array_keys($this->pages) as $page) {
            if (in_array($panel, $this->panels($page), true)) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    // ---- dimensions ---------------------------------------------------------

    /**
     * Declare a dimension: an attribute promoted to a facet, group-by option,
     * filter chip and entity page, with an optional link back into the host.
     *
     *     TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus',
     *         link: fn ($id) => route('customers.show', $id));
     *
     * @param  (Closure(string): (string|null))|string|null  $link  URL template with `{value}` or a closure
     * @param  list<string>|null  $signals  Explore signals that facet on it (default requests + traces)
     */
    public function dimension(
        string $key,
        ?string $label = null,
        ?string $group = null,
        Closure|string|null $link = null,
        ?string $entity = null,
        ?string $scope = null,
        ?array $signals = null,
        ?string $format = null,
        ?string $plural = null,
        ?Closure $resolve = null,
        ?string $from = null,
        ?string $pattern = null,
    ): self {
        $existing = $this->dimensionRegistry()->get($key);

        // `from:` + `pattern:` read the value out of another attribute —
        // "the screen is the part of http.route after `hubhus:`".
        $derived = $from !== null
            ? new Derivation($from, $pattern ?? Derivation::PLACEHOLDER)
            : $existing?->derived;

        $this->dimensionRegistry()->add(new Dimension(
            key: $key,
            label: $label ?? $existing->label ?? $key,
            group: $group ?? $existing?->group,
            link: $link ?? $existing?->link,
            entity: $entity ?? $existing?->entity,
            scope: $scope ?? $existing->scope ?? 'span',
            builtin: false,
            signals: $signals ?? $existing->signals ?? ['requests', 'traces'],
            format: $format ?? $existing?->format,
            plural: $plural ?? $existing?->plural,
            resolve: $resolve ?? $existing?->resolve,
            derived: $derived,
        ));

        return $this;
    }

    /**
     * Show names instead of bare ids for a dimension — built-in or declared —
     * without changing anything else about it:
     *
     *     TelemetryUi::resolve('user.id', User::class, 'name');
     *     TelemetryUi::resolve('hubhus.customer_id', Customer::class, fn (Customer $c) => $c->company);
     *     TelemetryUi::resolve('tenant.id', fn (array $ids) => Tenant::whereIn('uuid', $ids)->pluck('name', 'uuid'));
     *
     * With a model class the values are matched on `$column` (default: the
     * model's key) and `$display` is an attribute name or a closure receiving
     * the model. With a closure it gets a batch of values and returns
     * `[value => name]`. Lookups are batched and cached by the API
     * (`telemetry-ui.dimensions.label_ttl`) and fail open.
     *
     * @param  class-string|Closure(list<string>): iterable<array-key, mixed>  $using
     * @param  string|Closure(object): mixed  $display
     */
    public function resolve(string $key, string|Closure $using, string|Closure $display = 'name', ?string $column = null): self
    {
        $resolver = $using instanceof Closure ? $using : self::modelResolver($using, $display, $column);
        $existing = $this->dimensionRegistry()->get($key);

        $this->dimensionRegistry()->add($existing !== null
            ? $existing->withResolver($resolver)
            : new Dimension(key: $key, label: $key, resolve: $resolver));

        return $this;
    }

    /**
     * @param  string|Closure(object): mixed  $display
     * @return Closure(list<string>): array<string, mixed>
     */
    private static function modelResolver(string $model, string|Closure $display, ?string $column): Closure
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("TelemetryUi::resolve() expects an Eloquent model class or a closure, got [{$model}].");
        }

        return static function (array $values) use ($model, $display, $column): array {
            $instance = new $model;
            $column ??= $instance->getKeyName();
            $names = [];

            foreach ($model::query()->whereIn($column, $values)->limit(count($values))->get() as $row) {
                $names[(string) $row->getAttribute($column)] = $display instanceof Closure ? $display($row) : $row->getAttribute($display);
            }

            return $names;
        };
    }

    public function removeDimension(string $key): self
    {
        $this->dimensionRegistry()->remove($key);

        return $this;
    }

    public function dimensions(): Dimensions
    {
        return $this->dimensionRegistry();
    }

    private ?Dimensions $dimensionRegistry = null;

    private function dimensionRegistry(): Dimensions
    {
        return $this->dimensionRegistry ??= new Dimensions;
    }

    // ---- entities -----------------------------------------------------------

    /**
     * Entity slugs whose page also runs a v1-style detail page's panels,
     * scoped by one query param: `route` → the request-detail panels with
     * `?route={value}`.
     *
     * @var array<string, array{page: string, param: string}>
     */
    private array $entityPages = [
        'route' => ['page' => 'request-detail', 'param' => 'route'],
        'job' => ['page' => 'job-detail', 'param' => 'job'],
        'queue' => ['page' => 'queue-detail', 'param' => 'queue'],
        'host' => ['page' => 'host-detail', 'param' => 'host'],
        'query' => ['page' => 'query-detail', 'param' => 'dbq'],
        'outgoing' => ['page' => 'outgoing-detail', 'param' => 'host'],
        'path' => ['page' => 'page-detail', 'param' => 'path'],
    ];

    /**
     * Attach a page's panels to an entity type's story page.
     */
    public function entityPage(string $entity, string $page, string $param): self
    {
        $this->entityPages[$entity] = ['page' => $page, 'param' => $param];

        return $this;
    }

    /**
     * @return array{page: string, param: string}|null
     */
    public function entityPageFor(string $entity): ?array
    {
        return $this->entityPages[$entity] ?? null;
    }
}
