<?php

declare(strict_types=1);
use Cbox\TelemetryUi\TelemetryUiServiceProvider;

it('registers no routes when disabled', function (): void {
    $this->get('/telemetry-ui')->assertNotFound();
    $this->get('/telemetry-ui/assets/telemetry-ui.css')->assertNotFound();
});

it('still allows config publishing when disabled', function (): void {
    expect(app()->providerIsLoaded(TelemetryUiServiceProvider::class))->toBeTrue()
        ->and(config('telemetry-ui.enabled'))->toBeFalse();
});
