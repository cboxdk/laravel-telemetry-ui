<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Explore\LogExplorer;
use Cbox\TelemetryUi\Explore\SpanExplorer;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Filter;
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
    private const MAX_KEYS = 60;

    public function __invoke(Request $request, SpanExplorer $spans, LogExplorer $logs, ErrorExplorer $errors, string $signal): JsonResponse
    {
        // The same per-page gate Explore applies: facet values are data.
        if (! Gate::allows('viewTelemetryUi', [ExploreController::PAGE_FOR[$signal] ?? $signal])) {
            return ApiError::forbidden();
        }

        $scope = RequestScope::fromRequest($request);
        // Keys are spliced into the backend query as identifiers, and every
        // one costs a select field plus a read-side fold: validate and bound.
        $keys = array_slice(array_values(array_filter(
            (array) $request->query('keys', []),
            static fn ($k): bool => is_string($k) && Filter::isKey($k),
        )), 0, self::MAX_KEYS);
        $limit = max(1, min(SpanExplorer::MAX_LIMIT, (int) $request->query('limit', (string) SpanExplorer::DEFAULT_LIMIT)));

        try {
            $payload = match ($signal) {
                'logs' => $logs->facets($scope, $keys, $limit),
                'errors' => $errors->facets($scope, $keys),
                default => $spans->facets($scope, $signal, $keys, $limit),
            };
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok(['signal' => $signal, ...$payload]);
    }
}
