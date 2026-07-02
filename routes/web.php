<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Http\Controllers\AssetController;
use Cbox\TelemetryUi\Http\Controllers\PageController;
use Cbox\TelemetryUi\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

Route::get('/assets/{asset}', AssetController::class)
    ->where('asset', '[a-z0-9\-\.]+')
    ->withoutMiddleware(Authorize::class)
    ->name('telemetry-ui.asset');

Route::get('/{page?}', PageController::class)
    ->where('page', '[a-z0-9\-]*')
    ->name('telemetry-ui.page');
