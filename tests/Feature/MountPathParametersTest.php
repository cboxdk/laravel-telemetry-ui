<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\TelemetryUi\Support\DashboardUrl;
use Cbox\TelemetryUi\Support\MountPath;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Illuminate\Routing\RouteCollection;

/*
| A host that keeps its tenant in the URL mounts the dashboard under a route parameter,
| so two tabs on two tenants are two dashboards and never share state.
*/

beforeEach(function (): void {
    config()->set('telemetry-ui.path', 'projects/{project}/telemetry');

    // Routes are registered at boot from config; register them again for this path.
    app('router')->setRoutes(new RouteCollection);
    (fn () => $this->registerRoutes())->call(new TelemetryUiServiceProvider(app()));
    app('router')->getRoutes()->refreshNameLookups();
    app('url')->setRoutes(app('router')->getRoutes());
});

it('names the mount path\'s parameters and turns them into a wildcard pattern', function (): void {
    expect(MountPath::parameters())->toBe(['project'])
        ->and(MountPath::pattern())->toBe('projects/*/telemetry');
});

it('boots the client at the mount it was opened at', function (): void {
    $html = $this->get('/projects/prj_one/telemetry')->assertOk()->getContent();

    expect($html)->toContain('"base":"/projects/prj_one/telemetry"')
        ->and($html)->toContain('"api":"/projects/prj_one/telemetry/api/v2"');

    // Another tenant in another tab: its own mount, nothing carried over.
    $other = $this->get('/projects/prj_two/telemetry/traces')->assertOk()->getContent();
    expect($other)->toContain('"base":"/projects/prj_two/telemetry"');
});

it('keeps the host\'s parameter away from the dashboard\'s own route parameters', function (): void {
    // PageController takes {page}; the mount's {project} must not arrive in its place.
    $this->getJson('/projects/prj_one/telemetry/api/v2/pages/dashboard')
        ->assertOk()
        ->assertJsonPath('page', 'dashboard');
});

it('builds links inside the same mount', function (): void {
    $this->get('/projects/prj_one/telemetry');

    expect(DashboardUrl::page('traces/abc'))->toEndWith('/projects/prj_one/telemetry/traces/abc');
});

it('ignores its own requests under every value of the parameter', function (): void {
    app()->register(TelemetryServiceProvider::class);
    app()->forgetInstance(TelemetryManager::class);
    (fn () => $this->ignoreOwnRequests())->call(new TelemetryUiServiceProvider(app()));

    expect(app(TelemetryManager::class)->ignoredPaths())->toContain('projects/*/telemetry/*');
});
