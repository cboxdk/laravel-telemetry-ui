<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/annotations` — deploy/change markers in the scope's range, for
 * the SPA's own charts (Explore, entity trends). Panel charts carry theirs.
 */
final class AnnotationsController
{
    public function __invoke(Request $request, Annotations $annotations): JsonResponse
    {
        $scope = RequestScope::fromRequest($request);
        [$start, $end] = $scope->range();

        try {
            $marks = $annotations->between($start, $end, $scope->logQuery());
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok(['annotations' => array_map(static fn (Annotation $a): array => $a->toMarkLine(), $marks)]);
    }
}
