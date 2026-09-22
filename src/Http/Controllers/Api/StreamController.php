<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Illuminate\Http\JsonResponse;

final class StreamController
{
    public function __invoke(): JsonResponse
    {
        return ApiError::notFound('Not implemented yet.');
    }
}
