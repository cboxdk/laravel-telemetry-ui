<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

use Illuminate\Http\JsonResponse;

/**
 * JSON responses for the v2 API. Unescaped slashes/unicode keep payloads
 * readable (routes, paths and queries are full of `/`), and NaN/INF from a
 * PromQL quantile over no data are turned into null rather than failing the
 * whole encode.
 */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param  array<mixed>  $data
     */
    public static function ok(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(self::clean($data), $status, ['Cache-Control' => 'no-store'], self::FLAGS);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function clean(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                $value = null;
            }
        });

        return $data;
    }
}
