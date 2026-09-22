<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Dimensions\Dimension;
use Cbox\TelemetryUi\Http\Api\Brand;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Support\ConnectionOption;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\MetricScope;
use Cbox\TelemetryUi\Support\NavLink;
use Cbox\TelemetryUi\Support\Period;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ScopeLock;
use Cbox\TelemetryUi\Support\ViewState;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * `GET /api/v2/bootstrap` — everything the SPA needs before its first screen:
 * navigation (detection- and gate-filtered, so the nav never advertises a page
 * the gate would refuse), the scope picker's options and locks, the reader's
 * remembered view state, the dimension registry, entity types, host links and
 * connection profiles, and the viewer's abilities.
 */
final class BootstrapController
{
    public function __invoke(
        Request $request,
        TelemetryUiManager $manager,
        SchemaDetector $detector,
        Fleet $fleet,
        ScopeLock $lock,
        ViewState $state,
        ConnectionManager $connections,
    ): JsonResponse {
        $scope = RequestScope::fromRequest($request);

        $pages = array_filter(
            $manager->visiblePages($detector, app(MetricScope::class)->promMatchers($scope->service, $scope->environment)),
            static fn (array $meta, string $slug): bool => Gate::allows('viewTelemetryUi', [$slug]),
            ARRAY_FILTER_USE_BOTH,
        );

        $groups = [];

        foreach ($pages as $slug => $meta) {
            if ($meta['hidden'] ?? false) {
                continue;
            }

            $group = $meta['group'] ?? 'Overview';
            $groups[$group][] = ['slug' => $slug, 'label' => $meta['label']];
        }

        $services = $environments = [];
        $scopeError = null;

        try {
            $services = $fleet->services();
            $environments = $fleet->environments();
        } catch (Throwable $exception) {
            $scopeError = $exception->getMessage();
        }

        $dimensions = $manager->dimensions()->all();
        $user = Auth::user();

        return Json::ok([
            'app' => [
                ...Brand::toArray(),
                'copyLink' => (bool) config('telemetry-ui.copy_link', true),
                'version' => '2.0',
            ],
            'nav' => array_map(
                static fn (string $group, array $items): array => ['group' => $group, 'pages' => $items],
                array_keys($groups),
                array_values($groups),
            ),
            'pages' => array_map(static fn (array $meta): array => [
                'label' => $meta['label'],
                'group' => $meta['group'],
                'hidden' => (bool) ($meta['hidden'] ?? false),
            ], $pages),
            'explore' => array_values(array_filter([
                Gate::allows('viewTelemetryUi', ['requests']) ? ['signal' => 'requests', 'label' => 'Requests'] : null,
                Gate::allows('viewTelemetryUi', ['traces']) ? ['signal' => 'traces', 'label' => 'Traces'] : null,
                Gate::allows('viewTelemetryUi', ['logs']) ? ['signal' => 'logs', 'label' => 'Logs'] : null,
                Gate::allows('viewTelemetryUi', ['exceptions']) ? ['signal' => 'errors', 'label' => 'Errors'] : null,
            ])),
            'entities' => array_values(array_map(
                static fn (Dimension $d): array => ['type' => $d->entitySlug(), 'key' => $d->key, 'label' => $d->label, 'plural' => $d->plural ?? $d->label.'s', 'custom' => ! $d->builtin, 'group' => $d->group],
                array_filter($dimensions, static fn (Dimension $d): bool => $d->entity !== null || ! $d->builtin),
            )),
            'dimensions' => array_map(static fn (Dimension $d): array => $d->toArray(), $dimensions),
            'navLinks' => array_map(static fn (NavLink $l): array => ['key' => $l->key, 'label' => $l->label, 'url' => $l->url, 'icon' => $l->icon], $manager->navLinks()),
            'connections' => array_map(static fn (ConnectionOption $c): array => ['value' => $c->value, 'label' => $c->label, 'url' => $c->url], $manager->connections()),
            'currentConnection' => $manager->selectedConnection(),
            'scope' => [
                'services' => $services,
                'environments' => $environments,
                'servicesLocked' => $lock->servicesLocked(),
                'environmentsLocked' => $lock->environmentsLocked(),
                'error' => $scopeError,
            ],
            'state' => $state->toArray(),
            'periods' => array_map(static fn (Period $p): array => ['value' => $p->value, 'label' => $p->label()], Period::cases()),
            'refreshIntervals' => ViewState::INTERVALS,
            'abilities' => [
                'manage' => Gate::allows('manageTelemetryUi'),
                'createIssues' => Gate::allows('manageTelemetryUi') && $connections->hasIssues() && $connections->issues() instanceof CreatesIssues,
            ],
            'capabilities' => [
                'issues' => $connections->hasIssues(),
                'exactAggregation' => self::aggregates($connections),
            ],
            'user' => $user !== null ? [
                'name' => (string) (data_get($user, 'name') ?? data_get($user, 'email') ?? 'User'),
                'email' => data_get($user, 'email'),
            ] : null,
        ]);
    }

    private static function aggregates(ConnectionManager $connections): bool
    {
        try {
            return $connections->traces() instanceof AggregatesSpans;
        } catch (Throwable) {
            return false;
        }
    }
}
