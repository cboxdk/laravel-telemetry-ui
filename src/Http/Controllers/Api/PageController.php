<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Support\MetricScope;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/pages/{page}` — a registered page and the panels on it, in
 * order. The SPA fetches each panel on its own, so one slow backend query never
 * blocks the page.
 */
final class PageController
{
    public function __invoke(Request $request, TelemetryUiManager $manager, SchemaDetector $detector, string $page): JsonResponse
    {
        $meta = $manager->pages()[$page] ?? null;

        if ($meta === null) {
            return ApiError::notFound("Unknown page [{$page}].");
        }

        if (! Gate::allows('viewTelemetryUi', [$page])) {
            return ApiError::forbidden();
        }

        // A page whose metric family the backends don't carry (for the scoped
        // service) is not in the nav, and a deep link to it 404s rather than
        // rendering a grid of empty panels — as v1's page route did.
        if (($meta['detect'] ?? null) !== null) {
            $scope = RequestScope::fromRequest($request);
            $visible = $manager->visiblePages($detector, app(MetricScope::class)->promMatchers($scope->service, $scope->environment));

            if (! isset($visible[$page])) {
                return ApiError::notFound("Page [{$page}] has no data in this scope.");
            }
        }

        return Json::ok([
            'page' => $page,
            'label' => $meta['label'],
            'group' => $meta['group'],
            'panels' => [
                ...array_map(static fn (string $panel): array => [
                    'id' => $panel::id(),
                    'span' => $panel::span(),
                ], $manager->panels($page)),
                // Panels the host declared rather than coded (metricPanel()).
                ...array_map(
                    static fn (string $id, array $spec): array => ['id' => $id, 'span' => $spec['span']],
                    array_keys($manager->declaredPanels($page)),
                    array_values($manager->declaredPanels($page)),
                ),
            ],
        ]);
    }
}
