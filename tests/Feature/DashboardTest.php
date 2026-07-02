<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\ExceptionsOverview;
use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;
use Cbox\TelemetryUi\Cards\Builtin\RequestDuration;
use Cbox\TelemetryUi\Cards\Builtin\RequestsActivity;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\TelemetryUiManager;
use Cbox\TelemetryUi\Tests\Fixtures\DummyCard;
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
        ->assertSee('Requests')
        ->assertSee('Duration')
        ->assertSee('Exceptions')
        ->assertSee('Jobs');
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

    $manager->card(DummyCard::class);

    // Config-declared dashboard cards come first, runtime additions after,
    // and re-registering an existing card does not duplicate it.
    expect($manager->cards())->toBe([
        RequestsActivity::class,
        RequestDuration::class,
        ExceptionsOverview::class,
        JobsOverview::class,
        DummyCard::class,
    ]);

    $manager->page('autoscale', 'Autoscale', group: 'Activity');
    $manager->card(DummyCard::class, page: 'autoscale');

    expect($manager->cards('autoscale'))->toBe([DummyCard::class])
        ->and($manager->pages())->toHaveKeys(['dashboard', 'autoscale', 'requests', 'jobs', 'traces']);
});
