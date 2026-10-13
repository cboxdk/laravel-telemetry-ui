<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Monitors\Monitor;
use Cbox\TelemetryUi\Monitors\MonitorException;
use Cbox\TelemetryUi\Monitors\MonitorRequest;
use Cbox\TelemetryUi\Monitors\Monitors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Monitors (cboxdk/laravel-telemetry directives):
 * `GET /api/v2/monitors` lists the active ones on every service this viewer
 * may monitor, `POST /api/v2/monitors` places one, `DELETE
 * /api/v2/monitors/{service}/{id}` removes one. Reading follows the
 * `monitors` page ability; writing needs `manageTelemetryUi`. The scope
 * rule lives in {@see Monitors}.
 */
final class MonitorsController
{
    public function index(Monitors $monitors): JsonResponse
    {
        if (! Gate::allows('viewTelemetryUi', ['monitors'])) {
            return ApiError::forbidden();
        }

        $list = $monitors->list();

        return Json::ok([
            'services' => $monitors->services(),
            'monitors' => array_map(static fn (Monitor $m): array => $m->toArray(), $list['monitors']),
            'errors' => $list['errors'],
            'now' => time(),
            'canManage' => Gate::allows('manageTelemetryUi'),
        ]);
    }

    public function store(Request $request, Monitors $monitors): JsonResponse
    {
        if (! Gate::allows('manageTelemetryUi')) {
            return ApiError::forbidden('You are not authorized to place monitors.');
        }

        $service = $request->input('service');
        $match = $request->input('match');
        $value = $request->input('value');
        $key = $request->input('key');
        $minutes = $request->input('minutes', 60);
        $reason = $request->input('reason');

        if (! is_string($service) || ! is_string($match) || ! (is_string($value) || is_int($value)) || ! is_numeric($minutes)) {
            return ApiError::invalid('A monitor needs service, match, value and minutes.');
        }

        try {
            $monitor = $monitors->add($service, MonitorRequest::make(
                match: $match,
                key: is_string($key) ? $key : null,
                value: (string) $value,
                minutes: (int) $minutes,
                profile: $request->boolean('profile'),
                reason: is_string($reason) ? $reason : null,
                createdBy: Monitors::actor(Auth::user()),
            ));
        } catch (MonitorException $e) {
            return self::error($e);
        }

        return Json::ok(['monitor' => $monitor->toArray()], 201);
    }

    public function destroy(Monitors $monitors, string $service, string $id): JsonResponse
    {
        if (! Gate::allows('manageTelemetryUi')) {
            return ApiError::forbidden('You are not authorized to remove monitors.');
        }

        try {
            $monitors->remove($service, $id);
        } catch (MonitorException $e) {
            return self::error($e);
        }

        return Json::ok(['removed' => $id]);
    }

    private static function error(MonitorException $e): JsonResponse
    {
        return match ($e->kind) {
            'forbidden' => ApiError::forbidden($e->getMessage()),
            'invalid' => ApiError::invalid($e->getMessage()),
            'not_found' => ApiError::notFound($e->getMessage()),
            default => ApiError::backend($e->getMessage()),
        };
    }
}
