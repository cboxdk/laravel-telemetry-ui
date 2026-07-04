<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('telemetry-ui.annotations.auto_version.enabled', true);

    // AnnotationWriter (resolved even on the disabled path via method
    // injection) needs the emitter; bind a mock for every test.
    $this->telemetry = Mockery::mock(TelemetryManager::class);
    $this->telemetry->shouldReceive('enabled')->andReturnTrue()->byDefault();
    app()->instance(TelemetryManager::class, $this->telemetry);
});

it('annotates only versions that are live but not yet annotated', function (): void {
    // v1 already has an annotation in Loki; v2 is new.
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['laravel_version' => 'v1', 'service_name' => 'cbox-web'], 'value' => [1735689600, '1']],
                ['metric' => ['laravel_version' => 'v2', 'service_name' => 'cbox-web'], 'value' => [1735689600, '1']],
            ]],
        ]),
        'loki.test:3100/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                ['stream' => ['event' => 'app.version', 'version_id' => 'v1'], 'values' => [['1735689600000000000', 'app.version']]],
            ]],
        ]),
    ]);

    // Only v2 is emitted — v1 was already annotated.
    $this->telemetry->shouldReceive('event')->once()->with('app.version', [
        'version.id' => 'v2',
        'version.notes' => 'First seen in production (cbox-web)',
    ]);
    $this->telemetry->shouldReceive('flush')->once();

    $this->artisan('telemetry-ui:scan-versions')
        ->expectsOutputToContain('Annotated newly-seen version: v2')
        ->assertExitCode(0);
});

it('does nothing when the command is disabled', function (): void {
    config()->set('telemetry-ui.annotations.auto_version.enabled', false);
    $this->telemetry->shouldReceive('event')->never();

    $this->artisan('telemetry-ui:scan-versions')
        ->expectsOutputToContain('off')
        ->assertExitCode(0);
});

it('refuses to emit when the annotation store is unreadable (avoids duplicates)', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => ['laravel_version' => 'v9', 'service_name' => 'cbox-web'], 'value' => [1735689600, '1']],
            ]],
        ]),
        'loki.test:3100/*' => Http::response('loki down', 502),
    ]);

    $this->telemetry->shouldReceive('event')->never();

    $this->artisan('telemetry-ui:scan-versions')->assertExitCode(0);
});
