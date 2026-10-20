<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

use Cbox\TelemetryUi\Connectors\BackendStatus;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Http\JsonResponse;

/**
 * Typed API errors, so the SPA renders the right state (backend down vs
 * forbidden vs a bad request) instead of a blank panel:
 *
 *     {"error": {"type": "backend", "message": "Tempo returned 503"}}
 */
final class ApiError
{
    public const BACKEND = 'backend';

    public const FORBIDDEN = 'forbidden';

    public const INVALID = 'invalid';

    public const NOT_FOUND = 'not_found';

    public static function response(string $type, string $message, ?BackendStatus $backend = null): JsonResponse
    {
        $status = match ($type) {
            self::BACKEND => 502,
            self::FORBIDDEN => 403,
            self::INVALID => 422,
            self::NOT_FOUND => 404,
            default => 500,
        };

        $error = ['type' => $type, 'message' => $message];

        // What went wrong at the backend, so the dashboard can tell a read
        // worth repeating (a dropped connection) from one that would only
        // wait out the same timeout again.
        if ($backend !== null) {
            $error['backend'] = $backend->value;
        }

        return new JsonResponse(['error' => $error], $status, [], Json::FLAGS);
    }

    public static function backend(string $message, ?BackendStatus $backend = null): JsonResponse
    {
        return self::response(self::BACKEND, $message, $backend);
    }

    /** A backend failure, with what kind of failure it was. */
    public static function fromSource(SourceException $exception): JsonResponse
    {
        return self::backend($exception->getMessage(), $exception->status);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::response(self::NOT_FOUND, $message);
    }

    public static function invalid(string $message): JsonResponse
    {
        return self::response(self::INVALID, $message);
    }

    public static function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return self::response(self::FORBIDDEN, $message);
    }
}
