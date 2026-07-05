<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\RequestsActivity;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
    ]);
});

it('mounts a card as a widget with scope from props, over the URL', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Livewire::test(RequestsActivity::class, ['service' => 'cbox-web', 'period' => '24h'])
        ->assertSet('embedded', true)
        ->assertSet('service', 'cbox-web')
        ->assertSet('period', '24h');

    // The scope reached the query, not the (empty) URL default.
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode(requestQuery($request)['query'] ?? ''), 'service_name="cbox-web"'));
});

it('gates an embedded widget — no viewTelemetryUi, no data', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => false);

    // mount() enforces the gate for embedded use (scope passed) and 403s.
    expect(fn () => (new RequestsActivity)->mount(service: 'cbox-web'))
        ->toThrow(HttpException::class);
});

it('does not gate a non-embedded card mount (the dashboard route already did)', function (): void {
    // No gate defined (denies by default in testing), but a bare mount is not
    // "embedded", so it renders — the page route is what enforces the gate.
    Livewire::test(RequestsActivity::class)->assertSet('embedded', false);
});

it('exposes the asset tags via the @telemetryUiAssets directive', function (): void {
    expect(Blade::render('@telemetryUiAssets'))
        ->toContain('telemetry-ui.js')
        ->toContain('telemetry-ui.css');
});
