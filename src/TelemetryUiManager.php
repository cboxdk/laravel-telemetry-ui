<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Cards\Builtin;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Support\SchemaDetector;
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
        'commands' => ['label' => 'Commands', 'group' => 'Activity', 'icon' => null, 'detect' => 'commands_.*'],
        'schedule' => ['label' => 'Scheduled Tasks', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exceptions' => ['label' => 'Exceptions', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'exception-detail' => ['label' => 'Exception', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'queries' => ['label' => 'Queries', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'cache' => ['label' => 'Cache', 'group' => 'Activity', 'icon' => null, 'detect' => 'cache_operations.*'],
        'outgoing' => ['label' => 'Outgoing Requests', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'outgoing-detail' => ['label' => 'Host', 'group' => null, 'icon' => null, 'detect' => null, 'hidden' => true],
        'mail' => ['label' => 'Mail & Notifications', 'group' => 'Activity', 'icon' => null, 'detect' => null],
        'analytics' => ['label' => 'Analytics', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'frontend' => ['label' => 'Frontend', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
        'hosts' => ['label' => 'Hosts', 'group' => 'Monitoring', 'icon' => null, 'detect' => null],
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
        'requests' => [Builtin\RequestsActivity::class, Builtin\RequestDuration::class, Builtin\RoutesTable::class],
        'request-detail' => [Builtin\Detail\RequestDetailHeader::class, Builtin\Detail\RequestDetailActivity::class, Builtin\Detail\RequestDetailDuration::class, Builtin\Detail\RequestDetailStatus::class, Builtin\Detail\RequestDetailPaths::class, Builtin\Detail\RequestDetailTraces::class],
        'jobs' => [Builtin\JobsOverview::class, Builtin\QueueLag::class, Builtin\JobsTable::class],
        'job-detail' => [Builtin\Detail\JobDetailHeader::class, Builtin\Detail\JobDetailOutcomes::class, Builtin\Detail\JobDetailTraces::class],
        'commands' => [Builtin\CommandsOverview::class, Builtin\CommandsTable::class],
        'schedule' => [Builtin\ScheduleOverview::class, Builtin\ScheduleTable::class],
        'exceptions' => [Builtin\UnifiedErrors::class, Builtin\ExceptionsOverview::class, Builtin\ExceptionsTable::class],
        'exception-detail' => [Builtin\Detail\ExceptionDetailHeader::class, Builtin\Detail\ExceptionDetailTrend::class, Builtin\Detail\ExceptionDetailTraces::class],
        'queries' => [Builtin\SlowQueries::class],
        'cache' => [Builtin\CacheOperations::class],
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
        'frontend' => [Builtin\FrontendPages::class, Builtin\FrontendFetches::class],
        'hosts' => [Builtin\HostsTable::class],
        'users' => [Builtin\TrafficByFacet::class],
        'logs' => [Builtin\LogViewer::class],
        'system' => [Builtin\SystemMemory::class, Builtin\SystemCpu::class, Builtin\SystemFilesystem::class, Builtin\SystemNetwork::class],
    ];

    /**
     * Extra MCP tools contributed by apps/packages, appended to the built-in
     * read tools the TelemetryServer already exposes.
     *
     * @var list<class-string<Tool>>
     */
    private array $mcpTools = [];

    public function __construct(private readonly Config $config) {}

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
     * Register a card on a page.
     *
     * @param  class-string<Card>  $card
     */
    public function card(string $card, string $page = 'dashboard'): self
    {
        $this->cards[$page][] = $card;

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
     * Pages that should be visible given what the backends contain.
     *
     * @return array<string, PageMeta>
     */
    public function visiblePages(SchemaDetector $detector): array
    {
        return array_filter(
            $this->pages,
            static fn (array $meta): bool => $meta['detect'] === null || $detector->hasMetricsMatching($meta['detect']),
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
        $cards = $this->cards[$page] ?? [];

        if ($page === 'dashboard') {
            /** @var list<class-string<Card>> $configured */
            $configured = (array) $this->config->get('telemetry-ui.cards', []);

            $cards = [...$configured, ...$cards];
        }

        return array_values(array_unique($cards));
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
