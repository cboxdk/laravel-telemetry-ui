<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Events\DashboardViewed;
use Cbox\TelemetryUi\Support\ConnectionOption;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\MetricScope;
use Cbox\TelemetryUi\Support\NavLink;
use Cbox\TelemetryUi\Support\PaletteCommands;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ScopeLock;
use Cbox\TelemetryUi\Support\ViewState;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request;

/**
 * Shared chrome (command-palette) data for the page and trace views, so the
 * layout receives it as plain props instead of computing it in Blade.
 */
trait BuildsChrome
{
    /**
     * Pages the current user may both see (metric detection) and access (the
     * per-page gate) — so the sidebar and palette never advertise a page the
     * gate would 403. The route middleware still enforces access on its own.
     *
     * @return array<string, array{label: string, group: string|null, icon: string|null, detect: string|null, hidden?: bool}>
     */
    protected function accessiblePages(TelemetryUiManager $manager, SchemaDetector $detector): array
    {
        // Scope detection to the selected service/environment so an optional
        // group (Statamic, Horizon, …) only shows when THAT service emits it —
        // not because some other service in the fleet does. Read through the
        // view state, so a REMEMBERED service shapes the sidebar exactly like a
        // freshly-picked one and the nav can't disagree with the cards.
        $state = app(ViewState::class);

        $scope = app(MetricScope::class)->promMatchers($state->service(), $state->environment());

        return array_filter(
            $manager->visiblePages($detector, $scope),
            static fn (array $meta, string $slug): bool => Gate::allows('viewTelemetryUi', [$slug]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Scope-picker options plus the per-dimension lock flags. {@see Fleet}
     * already constrains the discovered values to the tenancy lock; the flags
     * let the switcher drop the "All" option for a locked dimension and hide the
     * picker entirely when a dimension is locked to a single forced value.
     * Enforcement still happens at query time via {@see ScopesQueries} — this is
     * purely so the picker never advertises a scope the viewer can't reach.
     *
     * @return array{services: list<string>, environments: list<string>, servicesLocked: bool, environmentsLocked: bool}
     */
    protected function scopeOptions(Fleet $fleet): array
    {
        $lock = app(ScopeLock::class);

        return [
            'services' => $fleet->services(),
            'environments' => $fleet->environments(),
            'servicesLocked' => $lock->servicesLocked(),
            'environmentsLocked' => $lock->environmentsLocked(),
        ];
    }

    /**
     * @param  array<string, array{label: string, group: string|null, icon: string|null, detect: string|null}>  $pages
     * @param  list<string>  $services
     * @param  list<string>  $environments
     * @return array{commands: list<array{type: string, label: string, group: string, href: string}>, traceBase: string, traceSentinel: string, navLinks: list<NavLink>, connections: list<ConnectionOption>, currentConnection: string}
     */
    protected function chrome(array $pages, array $services, array $environments, string $active): array
    {
        $query = array_filter([
            'period' => Request::query('period'),
            'from' => Request::query('from'),
            'to' => Request::query('to'),
            'service' => Request::query('service'),
            'env' => Request::query('env'),
        ], static fn ($value): bool => is_string($value) && $value !== '');

        $manager = app(TelemetryUiManager::class);

        return [
            'commands' => PaletteCommands::build($pages, $services, $environments, $active, $query),
            'traceBase' => PaletteCommands::traceBase($active, $query),
            'traceSentinel' => PaletteCommands::TRACE_SENTINEL,
            'navLinks' => $manager->navLinks(),
            'connections' => $manager->connections(),
            'currentConnection' => $manager->selectedConnection(),
        ];
    }

    /**
     * Fire the audit/usage event for a page view. The scope comes from the
     * shared {@see ViewState}, so the audit trail records the window the reader
     * actually saw — including a remembered one — and is coerced safely there
     * (an array-shaped `?service[]=` records as '' rather than 'Array').
     */
    protected function recordView(string $page): void
    {
        $state = app(ViewState::class);

        event(new DashboardViewed(Auth::user(), $page, $state->service(), $state->environment()));
    }
}
