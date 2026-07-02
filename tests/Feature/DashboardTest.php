<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\RequestsOverview;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

it('denies access outside the local environment by default', function (): void {
    $this->get('/telemetry-ui')->assertForbidden();
});

it('allows access when the gate permits', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    Http::fake([
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
    ]);

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Requests / min');
});

it('renders registered pages and 404s unknown ones', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    Http::fake([
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => []],
        ]),
    ]);

    TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity');

    $this->get('/telemetry-ui/autoscale')->assertOk()->assertSee('Autoscale');
    $this->get('/telemetry-ui/unknown')->assertNotFound();
});

it('serves the bundled assets without authorization', function (): void {
    $this->get('/telemetry-ui/assets/telemetry-ui.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');

    $this->get('/telemetry-ui/assets/telemetry-ui.js')
        ->assertOk();

    $this->get('/telemetry-ui/assets/secrets.env')->assertNotFound();
});

it('registers cards from config and runtime, deduplicated and in order', function (): void {
    $manager = app(TelemetryUiManager::class);

    $manager->card(RequestsOverview::class);

    expect($manager->cards())->toBe([RequestsOverview::class]);

    $manager->page('jobs', 'Jobs', group: 'Activity');
    $manager->card(RequestsOverview::class, page: 'jobs');

    expect($manager->cards('jobs'))->toBe([RequestsOverview::class])
        ->and($manager->pages())->toHaveKeys(['dashboard', 'jobs']);
});
