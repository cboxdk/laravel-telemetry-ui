<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;

// The real manager, so this breaks if laravel-telemetry's contract moves.
beforeEach(fn () => app()->register(TelemetryServiceProvider::class));

/** Re-run only the ignore registration, as a boot with the current config would. */
function registerIgnores(): void
{
    app()->forgetInstance(TelemetryManager::class);
    (fn () => $this->ignoreOwnRequests())->call(new TelemetryUiServiceProvider(app()));
}

it('asks laravel-telemetry to ignore the dashboard path', function () {
    expect(app(TelemetryManager::class)->ignoredPaths())
        ->toContain('telemetry-ui')
        ->toContain('telemetry-ui/*');
});

it('follows a custom mount path', function () {
    config()->set('telemetry-ui.path', 'ops/telemetry');
    registerIgnores();

    expect(app(TelemetryManager::class)->ignoredPaths())->toContain('ops/telemetry/*');
});
