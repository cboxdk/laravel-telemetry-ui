<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Panels\Builtin\Declared\MetricPanel;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/panels/{panel}` — one panel's payload. The panel must be
 * registered on at least one page the viewer may open (the per-page gate), so
 * a page an app hides from a user can't be read panel-by-panel either.
 */
final class PanelController
{
    public function __invoke(Request $request, TelemetryUiManager $manager, string $panel): JsonResponse
    {
        $class = $manager->findPanel($panel);
        $declared = $class === null ? $manager->declaredPanel($panel) : null;

        if ($class === null && $declared === null) {
            return ApiError::notFound("Unknown panel [{$panel}].");
        }

        $class ??= MetricPanel::class;
        $pages = $declared !== null ? [$declared['page']] : $manager->pagesFor($class);

        if ($pages !== [] && array_filter($pages, static fn (string $page): bool => Gate::allows('viewTelemetryUi', [$page])) === []) {
            return ApiError::forbidden();
        }

        try {
            $scope = RequestScope::fromRequest($request);
            // A declared panel is told which one it is.
            $data = (new $class($declared !== null ? $scope->with(['_panel' => $panel]) : $scope))->serve();
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok(['id' => $panel, 'span' => $declared['spec']['span'] ?? $class::span(), ...$data]);
    }
}
