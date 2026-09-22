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
 * `GET /api/v2/explore/{signal}` — the Explore surface: rows (newest first),
 * headline stats, a distribution series, the time × latency heatmap and an
 * optional group-by, for requests / traces / logs / errors.
 *
 * Params: the scope (`period`, `from`, `to`, `service`, `env`), `where[]`
 * filters, `q` (free text), `groupBy` (an attribute key), `limit`.
 */
final class ExploreController
{
    /** The page whose per-page gate covers each signal. */
    public const PAGE_FOR = ['requests' => 'requests', 'traces' => 'traces', 'logs' => 'logs', 'errors' => 'exceptions'];

    public function __invoke(Request $request, SpanExplorer $spans, LogExplorer $logs, ErrorExplorer $errors, string $signal): JsonResponse
    {
        if (! Gate::allows('viewTelemetryUi', [self::PAGE_FOR[$signal] ?? $signal])) {
            return ApiError::forbidden();
        }

        $scope = RequestScope::fromRequest($request);
        $limit = max(1, min(SpanExplorer::MAX_LIMIT, (int) $request->query('limit', (string) SpanExplorer::DEFAULT_LIMIT)));
        $groupBy = is_string($request->query('groupBy')) ? (string) $request->query('groupBy') : null;

        try {
            $payload = match ($signal) {
                'logs' => $logs->explore($scope, $limit, $groupBy),
                'errors' => $errors->explore($scope, $limit),
                default => $spans->explore($scope, $signal, $limit, $groupBy),
            };
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok([...$payload, 'where' => array_map(static fn ($f): string => $f->toString(), $scope->where)]);
    }
}
