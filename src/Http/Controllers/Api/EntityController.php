<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\EntityStory;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Entity pages. `GET /api/v2/entities/{type}` lists every value of the
 * entity's dimension with RED; `GET /api/v2/entities/{type}/story?value=…`
 * is one value's story. `{type}` is an entity slug (`route`, `query`, `host`)
 * or any declared dimension key (`billing.customer_id`). The value travels as a
 * query param because values (routes, SQL) are full of slashes.
 */
final class EntityController
{
    public function index(Request $request, EntityStory $stories, string $type): JsonResponse
    {
        // Entity stories are built from request spans: same per-page gate as
        // the Requests page, so a viewer denied it can't read it this way.
        if (! Gate::allows('viewTelemetryUi', ['requests'])) {
            return ApiError::forbidden();
        }

        $dimension = $stories->dimension($type);

        if ($dimension === null) {
            return ApiError::notFound("Unknown entity type [{$type}].");
        }

        try {
            return Json::ok($stories->index(RequestScope::fromRequest($request), $dimension));
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }
    }

    public function show(Request $request, EntityStory $stories, string $type): JsonResponse
    {
        // Entity stories are built from request spans: same per-page gate as
        // the Requests page, so a viewer denied it can't read it this way.
        if (! Gate::allows('viewTelemetryUi', ['requests'])) {
            return ApiError::forbidden();
        }

        $dimension = $stories->dimension($type);

        if ($dimension === null) {
            return ApiError::notFound("Unknown entity type [{$type}].");
        }

        $value = $request->query('value');

        if (! is_string($value) || $value === '') {
            return ApiError::invalid('An entity value is required (?value=…).');
        }

        try {
            return Json::ok($stories->story(RequestScope::fromRequest($request), $dimension, $value));
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }
    }
}
