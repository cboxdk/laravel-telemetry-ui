<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/dimensions/labels?key=user.id&values[]=17&values[]=20` — the
 * display names a dimension's resolver knows for a batch of values, as
 * `{"labels": {"17": "Jane Doe"}}`. The SPA batches every id on screen into
 * one call per dimension. Names are cached per value; values the resolver
 * doesn't know are cached as misses too, so a page of unknown ids doesn't hit
 * the host's database on every render.
 */
final class DimensionLabelsController
{
    private const MAX_VALUES = 200;

    public function __invoke(Request $request, TelemetryUiManager $manager, Cache $cache, Config $config): JsonResponse
    {
        $key = $request->query('key');
        $values = $request->query('values', []);

        if (! is_string($key) || $key === '' || ! is_array($values)) {
            return ApiError::invalid('Pass key and values[].');
        }

        $dimension = $manager->dimensions()->get($key);

        if ($dimension === null) {
            return ApiError::notFound("Unknown dimension [{$key}].");
        }

        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? mb_substr((string) $v, 0, 200) : '', $values),
            static fn (string $v): bool => $v !== '',
        )));

        if (count($values) > self::MAX_VALUES) {
            return ApiError::invalid('At most '.self::MAX_VALUES.' values per request.');
        }

        if ($dimension->resolve === null || $values === []) {
            return Json::ok(['labels' => new \stdClass]);
        }

        $ttl = (int) $config->get('telemetry-ui.dimensions.label_ttl', 300);
        $cacheKey = static fn (string $value): string => 'telemetry-ui:label:'.sha1($key."\0".$value);

        $labels = [];
        $missing = [];

        foreach ($values as $value) {
            $hit = $ttl > 0 ? $cache->get($cacheKey($value)) : null;

            if (is_string($hit)) {
                if ($hit !== '') {
                    $labels[$value] = $hit;
                }
            } else {
                $missing[] = $value;
            }
        }

        if ($missing !== []) {
            $resolved = $dimension->labelsFor($missing);

            foreach ($missing as $value) {
                if (isset($resolved[$value])) {
                    $labels[$value] = $resolved[$value];
                }

                if ($ttl > 0) {
                    // '' = known miss.
                    $cache->put($cacheKey($value), $resolved[$value] ?? '', $ttl);
                }
            }
        }

        return Json::ok(['labels' => $labels === [] ? new \stdClass : $labels]);
    }
}
