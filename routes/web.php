<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Http\Controllers\Api;
use Cbox\TelemetryUi\Http\Controllers\AssetController;
use Cbox\TelemetryUi\Http\Controllers\SpaController;
use Cbox\TelemetryUi\Http\Middleware\Authorize;
use Cbox\TelemetryUi\Http\Middleware\RemembersViewState;
use Illuminate\Support\Facades\Route;

// Built assets skip the gate AND the dashboard throttle: they're immutable,
// content-hashed static files, and a 429 on a JS chunk takes the whole app
// down. They skip view-state persistence too: a Set-Cookie on a far-future
// cacheable response is both pointless and a cache-poisoning hazard.
$throttle = config('telemetry-ui.throttle');

Route::get('/build/{path}', AssetController::class)
    ->where('path', '[A-Za-z0-9_\-\.\/]+')
    ->withoutMiddleware(array_filter([
        Authorize::class,
        RemembersViewState::class,
        is_string($throttle) && $throttle !== '' ? 'throttle:'.$throttle : null,
    ]))
    ->name('telemetry-ui.asset');

Route::prefix('api/v2')->name('telemetry-ui.api.')->group(static function (): void {
    Route::get('/bootstrap', Api\BootstrapController::class)->name('bootstrap');
    Route::post('/view-state', Api\ViewStateController::class)->name('view-state');
    Route::get('/pages/{page}', Api\PageController::class)->where('page', '[a-z0-9\-]+')->name('page');
    Route::get('/panels/{panel}', Api\PanelController::class)->where('panel', '[a-z0-9\-]+')->name('panel');
    Route::get('/explore/{signal}', Api\ExploreController::class)->where('signal', 'requests|traces|logs|errors')->name('explore');
    Route::get('/facets/{signal}', Api\FacetsController::class)->where('signal', 'requests|traces|logs|errors')->name('facets');
    Route::get('/entities/{type}', [Api\EntityController::class, 'index'])->where('type', '[A-Za-z0-9_\.\-]+')->name('entities');
    Route::get('/entities/{type}/story', [Api\EntityController::class, 'show'])->where('type', '[A-Za-z0-9_\.\-]+')->name('entity');
    Route::get('/traces/{traceId}', Api\TraceController::class)->where('traceId', '[0-9a-fA-F]{1,64}')->name('trace');
    Route::get('/errors/{group}', Api\ErrorGroupController::class)->where('group', '[A-Za-z0-9]{1,64}')->name('error');
    Route::get('/issues/{id}', [Api\IssueController::class, 'show'])->where('id', '.+')->name('issue');
    Route::post('/issues', [Api\IssueController::class, 'store'])->name('issues.store');
    Route::get('/annotations', Api\AnnotationsController::class)->name('annotations');
    Route::get('/dimensions/labels', Api\DimensionLabelsController::class)->name('dimension-labels');
    Route::get('/stream/{signal}', Api\StreamController::class)->where('signal', 'logs|requests')->name('stream');
});

// Everything else is the SPA: deep links (/explore/requests?where[]=…,
// /traces/…) must survive a reload, so the shell answers any path and the
// client router takes it from there.
Route::get('/{any?}', SpaController::class)
    ->where('any', '^(?!api/|build/).*$')
    ->name('telemetry-ui.spa');
