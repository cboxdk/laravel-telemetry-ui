<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryManager;
use Cbox\TelemetryUi\Support\AnnotationWriter;
use Mockery\MockInterface;

function fakeTelemetry(bool $enabled = true): MockInterface
{
    $telemetry = Mockery::mock(TelemetryManager::class);
    $telemetry->shouldReceive('enabled')->andReturn($enabled);
    app()->instance(TelemetryManager::class, $telemetry);

    return $telemetry;
}

it('emits a configured annotation marker through the telemetry pipeline', function (): void {
    $telemetry = fakeTelemetry();
    $telemetry->shouldReceive('event')->once()->with('app.incident', ['incident.notes' => 'checkout 5xx spike']);
    $telemetry->shouldReceive('flush')->once();

    $this->artisan('telemetry-ui:annotate', ['marker' => 'incident', '--notes' => 'checkout 5xx spike'])
        ->expectsOutputToContain('emitted')
        ->assertExitCode(0);
});

it('maps id and notes to the marker\'s dotted OTLP attributes', function (): void {
    $telemetry = fakeTelemetry();
    $telemetry->shouldReceive('event')->once()->with('app.scaling', [
        'scaling.id' => 'web',
        'scaling.notes' => '+2 workers',
    ]);
    $telemetry->shouldReceive('flush')->once();

    app(AnnotationWriter::class)->write('scaling', 'web', '+2 workers');
});

it('emits a cache purge marker with the cache.type attribute', function (): void {
    $telemetry = fakeTelemetry();
    $telemetry->shouldReceive('event')->once()->with('app.cache_purge', [
        'cache.type' => 'redis',
        'cache.notes' => 'full flush',
    ]);
    $telemetry->shouldReceive('flush')->once();

    $this->artisan('telemetry-ui:annotate', ['marker' => 'cache_purge', '--id' => 'redis', '--notes' => 'full flush'])
        ->expectsOutputToContain('emitted')
        ->assertExitCode(0);
});

it('rejects an unknown marker', function (): void {
    fakeTelemetry();

    $this->artisan('telemetry-ui:annotate', ['marker' => 'nope'])
        ->expectsOutputToContain('Unknown annotation marker')
        ->assertExitCode(1);
});

it('is a no-op (not an error) when telemetry is disabled', function (): void {
    $telemetry = fakeTelemetry(enabled: false);
    $telemetry->shouldNotReceive('event');

    $this->artisan('telemetry-ui:annotate', ['marker' => 'deploy', '--id' => 'v1.2.3'])
        ->expectsOutputToContain('disabled')
        ->assertExitCode(0);
});
