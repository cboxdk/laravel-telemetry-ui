<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Http\Controllers\AssetController;
use Cbox\TelemetryUi\Http\Controllers\PageController;
use Cbox\TelemetryUi\Http\Controllers\TraceController;
use Cbox\TelemetryUi\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

// Assets skip the gate AND the dashboard throttle: they're immutable,
// version-stamped static files, and a 429 on the JS bundle takes every
// chart down with it (Alpine components never register). The throttle
// budget belongs to the query-backed pages, not the chrome.
$throttle = config('telemetry-ui.throttle');

Route::get('/assets/{asset}', AssetController::class)
    ->where('asset', '[a-z0-9\-\.]+')
    ->withoutMiddleware(array_filter([
        Authorize::class,
        is_string($throttle) && $throttle !== '' ? 'throttle:'.$throttle : null,
    ]))
    ->name('telemetry-ui.asset');

Route::get('/traces/{traceId}', TraceController::class)
    ->where('traceId', '[0-9a-fA-F]+')
    ->name('telemetry-ui.trace');

Route::get('/{page?}', PageController::class)
    ->where('page', '[a-z0-9\-]*')
    ->name('telemetry-ui.page');
