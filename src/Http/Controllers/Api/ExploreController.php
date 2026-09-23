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
use Cbox\TelemetryUi\Queries\Compilers\LogqlCompiler;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
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
        // A trace search can match many spans per trace; sample fewer by default.
        $default = $signal === 'traces' ? 200 : SpanExplorer::DEFAULT_LIMIT;
        $limit = max(1, min(SpanExplorer::MAX_LIMIT, (int) $request->query('limit', (string) $default)));
        $groupBy = is_string($request->query('groupBy')) && Filter::isKey((string) $request->query('groupBy'))
            ? (string) $request->query('groupBy')
            : null;

        try {
            $payload = match ($signal) {
                'logs' => $logs->explore($scope, $limit, $groupBy),
                'errors' => $errors->explore($scope, $limit),
                default => $spans->explore($scope, $signal, $limit, $groupBy),
            };
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok([
            ...$payload,
            'where' => array_map(static fn ($f): string => $f->toString(), $scope->where),
            // What the backend was actually asked — copyable, so a filter in
            // the UI is a query you can paste into Grafana or curl.
            'query' => $this->compiled($scope, $signal, $spans, $logs, $errors),
        ]);
    }

    /**
     * @return array{language: string, text: string}|null
     */
    private function compiled(RequestScope $scope, string $signal, SpanExplorer $spans, LogExplorer $logs, ErrorExplorer $errors): ?array
    {
        try {
            return match ($signal) {
                'logs' => ['language' => 'LogQL', 'text' => (new LogqlCompiler)->compile($logs->query($scope))],
                'errors' => ['language' => 'LogQL', 'text' => (new LogqlCompiler)->compile($errors->query($scope))],
                default => ['language' => 'TraceQL', 'text' => (new TraceqlCompiler)->compile($spans->query($scope, $signal))],
            };
        } catch (\Throwable) {
            // A preview is a nicety: never fail the view over it.
            return null;
        }
    }
}
