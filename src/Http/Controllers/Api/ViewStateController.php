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

        foreach (['period', 'from', 'to', 'service', 'env'] as $key) {
            if ($request->has($key)) {
                $values[$key] = (string) $request->input($key, '');
            }
        }

        if ($request->has('refresh')) {
            $values['refresh'] = (int) $request->input('refresh', 0);
        }

        // put() always marks the state dirty (a host moving it means it), so
        // compare what the reader had with what they report: re-reporting the
        // same window must stay quiet — no Set-Cookie, no event.
        $before = $state->toArray();

        $state->put($values);

        $response = Json::ok(['state' => $state->toArray()]);

        if ($state->enabled() && $state->toArray() !== $before) {
            $response->headers->setCookie($state->cookie());
            event(new ViewStateChanged($state, Auth::user()));
        }

        return $response;
    }
}
