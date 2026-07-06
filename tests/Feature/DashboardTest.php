<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\DeploysTimeline;
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

it('removes and replaces the config-declared dashboard cards', function (): void {
    $manager = app(TelemetryUiManager::class);

    // The dashboard's default cards come from config, not the runtime map —
    // removeCard/setCards must still reach them.
    expect($manager->cards('dashboard'))->toContain(JobsOverview::class);

    $manager->removeCard(JobsOverview::class, 'dashboard');
    expect($manager->cards('dashboard'))
        ->not->toContain(JobsOverview::class)
        ->toContain(RequestsActivity::class); // the other built-ins remain

    // setCards replaces the whole page (and can blank it).
    $manager->setCards('dashboard', [DummyCard::class]);
    expect($manager->cards('dashboard'))->toBe([DummyCard::class]);

    $manager->setCards('dashboard', []);
    expect($manager->cards('dashboard'))->toBe([]);
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

it('exempts assets from the dashboard throttle (a 429 on the bundle kills every chart)', function (): void {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('telemetry-ui.asset')->excludedMiddleware())->toContain('throttle:120,1')
        ->and($routes->getByName('telemetry-ui.page')->middleware())->toContain('throttle:120,1');
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
        DeploysTimeline::class,
        DummyCard::class,
    ]);

    $manager->page('my-package', 'My Package', group: 'Activity');
    $manager->card(DummyCard::class, page: 'my-package');

    expect($manager->cards('my-package'))->toBe([DummyCard::class])
        ->and($manager->pages())->toHaveKeys(['dashboard', 'my-package', 'requests', 'jobs', 'traces']);
});

it('replaces, removes cards and removes whole pages (embed/white-label)', function (): void {
    $manager = app(TelemetryUiManager::class);

    // Swap a page's built-in cards for your own.
    $manager->setCards('requests', [DummyCard::class]);
    expect($manager->cards('requests'))->toBe([DummyCard::class]);

    // Drop a single built-in card.
    $manager->removeCard(RequestDuration::class, 'requests')->card(RequestsActivity::class, 'requests');
    expect($manager->cards('requests'))->toBe([DummyCard::class, RequestsActivity::class]);

    // Remove a whole section from the sidebar + routing.
    $manager->removePage('users');
    expect($manager->pages())->not->toHaveKey('users')
        ->and($manager->hasPage('users'))->toBeFalse();
});
