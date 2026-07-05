<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('white-labels the sidebar name and accent colour', function (): void {
    config()->set('telemetry-ui.brand.name', 'Acme Observability');
    config()->set('telemetry-ui.brand.accent', '#ff0066');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('Acme Observability')                 // sidebar brand
        ->assertSee('--tui-accent: #ff0066', false);      // accent override injected
});

it('sanitises the accent value', function (): void {
    config()->set('telemetry-ui.brand.accent', 'red;} body{display:none'); // injection attempt

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertDontSee('body{display:none', false);      // stripped to safe CSS-colour chars
});
