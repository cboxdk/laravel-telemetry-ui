<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Illuminate\Http\JsonResponse;

final class EntityController
{
    public function index(): JsonResponse
    {
        return ApiError::notFound('Not implemented yet.');
    }

    public function show(): JsonResponse
    {
        return ApiError::notFound('Not implemented yet.');
    }
}
