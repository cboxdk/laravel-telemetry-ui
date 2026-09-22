<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Explore\LogExplorer;
use Cbox\TelemetryUi\Explore\SpanExplorer;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/facets/{signal}` — top values per dimension for the facet
 * panel, within the current scope and filters. `keys[]` picks the facets
 * (default: the signal's built-in dimensions plus every declared one).
 * `exact` says whether counts cover every match or a labelled sample.
 */
final class FacetsController
{
    public function __invoke(Request $request, SpanExplorer $spans, LogExplorer $logs, ErrorExplorer $errors, string $signal): JsonResponse
    {
        // The same per-page gate Explore applies: facet values are data.
        if (! Gate::allows('viewTelemetryUi', [ExploreController::PAGE_FOR[$signal] ?? $signal])) {
            return ApiError::forbidden();
        }

        $scope = RequestScope::fromRequest($request);
        $keys = array_values(array_filter((array) $request->query('keys', []), static fn ($k): bool => is_string($k) && $k !== ''));

        try {
            $payload = match ($signal) {
                'logs' => $logs->facets($scope, $keys),
                'errors' => $errors->facets($scope, $keys),
                default => $spans->facets($scope, $signal, $keys),
            };
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok(['signal' => $signal, ...$payload]);
    }
}
