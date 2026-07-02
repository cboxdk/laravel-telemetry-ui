<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Cards\Builtin\StaticCacheOverview;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * Registry for dashboard pages and their cards. Registration is data-only
 * (class-strings and labels) so packages can contribute from their service
 * providers at zero boot cost.
 *
 * @phpstan-type PageMeta array{label: string, group: string|null, icon: string|null, detect: string|null}
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
        'statamic' => ['label' => 'Statamic', 'group' => 'Activity', 'icon' => null, 'detect' => 'statamic_.*'],
    ];

    /**
     * @var array<string, list<class-string<Card>>>
     */
    private array $cards = [
        'statamic' => [StaticCacheOverview::class],
    ];

    public function __construct(private readonly Config $config) {}

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
    ): self {
        $this->pages[$slug] = ['label' => $label, 'group' => $group, 'icon' => $icon, 'detect' => $detectMetric];

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
