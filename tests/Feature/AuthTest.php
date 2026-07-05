<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TraceDrawer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    // Fleet discovery + schema detection touch Prometheus; keep them quiet so
    // pages render without a real backend.
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('403s a page the per-page gate denies, and hides it from the nav', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => $page !== 'logs');

    $this->get('/telemetry-ui/logs')->assertForbidden();

    // An allowed page renders, but the denied page is gone from the sidebar/palette.
    $this->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertDontSee('telemetry-ui/logs');
});

it('allows a page the per-page gate permits', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    $this->get('/telemetry-ui/logs')->assertOk();
});

it('blocks issue creation without the manage ability and hides the compose form', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_x',
    ]);
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:compose-ticket', title: 'Boom', body: 'x', labels: [])
        ->assertSet('composing', true)
        ->assertSee('not authorized')   // compose form replaced by the notice
        ->call('submitTicket')
        ->assertSee('not authorized');

    // The write never reached the tracker.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.github.com'));
});
