<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Support\ViewState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /api/v2/view-state` — the SPA reports the window/scope the reader
 * moved to, so the next visit (a reload, a host link without a query string)
 * starts there, and a host listening for {@see ViewStateChanged} hears it.
 */
final class ViewStateController
{
    public function __invoke(Request $request, ViewState $state): JsonResponse
    {
        $values = [];

        foreach (['period', 'from', 'to', 'service', 'env', 'refresh'] as $key) {
            if ($request->has($key)) {
                $values[$key] = (string) $request->input($key, '');
            }
        }

        $state->put($values);

        $response = Json::ok(['state' => $state->toArray()]);

        if ($state->enabled() && $state->changed()) {
            $response->headers->setCookie($state->cookie());
            event(new ViewStateChanged($state, Auth::user()));
        }

        return $response;
    }
}
