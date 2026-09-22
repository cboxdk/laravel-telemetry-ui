<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Panels\Builtin\DeploysTimeline;
use Cbox\TelemetryUi\Panels\Builtin\ExceptionsOverview;
use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;
use Cbox\TelemetryUi\Panels\Builtin\RequestDuration;
use Cbox\TelemetryUi\Panels\Builtin\RequestsActivity;
use Cbox\TelemetryUi\Panels\Builtin\RoutesNeedingAttention;
use Cbox\TelemetryUi\TelemetryUiManager;
use Cbox\TelemetryUi\Tests\Fixtures\DummyPanel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

it('denies access outside the local environment by default', function (): void {
    // The service provider's default gate: local only. testbench runs as "testing".
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => app()->environment('local'));

    $this->get('/telemetry-ui')->assertForbidden();
    $this->getJson(apiUrl('pages/dashboard'))->assertForbidden()->assertJsonPath('error.type', 'forbidden');
});

it('lists the dashboard panels when the gate permits', function (): void {
    $this->getJson(apiUrl('pages/dashboard'))
        ->assertOk()
        ->assertJsonPath('page', 'dashboard')
        ->assertJsonPath('label', 'Dashboard')
        ->assertJsonPath('panels.*.id', [
            'requests-activity',
            'request-duration',
            'exceptions-overview',
            'jobs-overview',
            'routes-needing-attention',
            'deploys-timeline',
        ]);
});

it('serves each dashboard panel as typed json', function (string $panel): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['class' => '2xx'], 'values' => [[1735689600, '5'], [1735689660, '7']]],
        ]]]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->getJson(panelUrl($panel))
        ->assertOk()
        ->assertJsonPath('id', $panel)
        ->assertJsonStructure(['kind', 'span']);
})->with(['requests-activity', 'request-duration', 'exceptions-overview', 'jobs-overview', 'routes-needing-attention', 'deploys-timeline']);

it('removes and replaces the config-declared dashboard panels', function (): void {
    $manager = app(TelemetryUiManager::class);

    // The dashboard's default panels come from config, not the runtime map —
    // removePanel/setPanels must still reach them.
    expect($manager->panels('dashboard'))->toContain(JobsOverview::class);

    $manager->removePanel(JobsOverview::class, 'dashboard');
    expect($manager->panels('dashboard'))
        ->not->toContain(JobsOverview::class)
        ->toContain(RequestsActivity::class); // the other built-ins remain

    // setPanels replaces the whole page (and can blank it).
    $manager->setPanels('dashboard', [DummyPanel::class]);
    expect($manager->panels('dashboard'))->toBe([DummyPanel::class]);

    $this->getJson(apiUrl('pages/dashboard'))->assertOk()->assertJsonPath('panels.*.id', ['dummy-panel']);

    $manager->setPanels('dashboard', []);
    expect($manager->panels('dashboard'))->toBe([]);

    $this->getJson(apiUrl('pages/dashboard'))->assertOk()->assertJsonPath('panels', []);
});

it('serves registered pages and 404s unknown ones with a typed error', function (): void {
    TelemetryUi::page('my-package', 'My Package', group: 'Activity');
    TelemetryUi::panel(DummyPanel::class, 'my-package');

    $this->getJson(apiUrl('pages/my-package'))
        ->assertOk()
        ->assertJsonPath('label', 'My Package')
        ->assertJsonPath('group', 'Activity')
        ->assertJsonPath('panels', [['id' => 'dummy-panel', 'span' => 1]]);

    $this->getJson(panelUrl('dummy-panel'))->assertOk()->assertJsonPath('kind', 'chart');

    $this->getJson(apiUrl('pages/unknown'))->assertNotFound()->assertJsonPath('error.type', 'not_found');
    $this->getJson(panelUrl('unknown'))->assertNotFound()->assertJsonPath('error.type', 'not_found');
});

it('exempts built assets from the gate and the dashboard throttle (a 429 on a chunk kills the app)', function (): void {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('telemetry-ui.asset')->excludedMiddleware())->toContain('throttle:600,1')
        ->and($routes->getByName('telemetry-ui.spa')->middleware())->toContain('throttle:600,1')
        ->and($routes->getByName('telemetry-ui.api.panel')->middleware())->toContain('throttle:600,1');
});

it('registers panels from config and runtime, deduplicated and in order', function (): void {
    $manager = app(TelemetryUiManager::class);

    $manager->panel(DummyPanel::class);
    $manager->panel(DummyPanel::class);

    // Config-declared dashboard panels come first, runtime additions after,
    // and re-registering an existing panel does not duplicate it.
    expect($manager->panels())->toBe([
        RequestsActivity::class,
        RequestDuration::class,
        ExceptionsOverview::class,
        JobsOverview::class,
        RoutesNeedingAttention::class,
        DeploysTimeline::class,
        DummyPanel::class,
    ]);

    $manager->page('my-package', 'My Package', group: 'Activity');
    $manager->panel(DummyPanel::class, page: 'my-package');

    expect($manager->panels('my-package'))->toBe([DummyPanel::class])
        ->and($manager->pages())->toHaveKeys(['dashboard', 'my-package', 'requests', 'jobs', 'traces'])
        ->and($manager->findPanel('dummy-panel'))->toBe(DummyPanel::class)
        ->and($manager->pagesFor(DummyPanel::class))->toBe(['dashboard', 'my-package']);
});

it('replaces, removes panels and removes whole pages (white-label)', function (): void {
    $manager = app(TelemetryUiManager::class);

    // Swap a page's built-in panels for your own.
    $manager->setPanels('requests', [DummyPanel::class]);
    expect($manager->panels('requests'))->toBe([DummyPanel::class]);

    // Drop a single built-in panel.
    $manager->removePanel(RequestDuration::class, 'requests')->panel(RequestsActivity::class, 'requests');
    expect($manager->panels('requests'))->toBe([DummyPanel::class, RequestsActivity::class]);

    // Remove a whole section from the nav + API.
    $manager->removePage('users');
    expect($manager->pages())->not->toHaveKey('users')
        ->and($manager->hasPage('users'))->toBeFalse();

    $this->getJson(apiUrl('pages/users'))->assertNotFound();
});
