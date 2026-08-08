<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Support\NavLink;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('renders a host link in the rail', function (): void {
    TelemetryUi::navLink('profiles', 'Connections', '/desktop/profiles', 'connection');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('Connections')
        ->assertSee('href="/desktop/profiles"', false);
});

it('renders host links on trace pages too, so a drilled-in reader is not stranded', function (): void {
    TelemetryUi::navLink('profiles', 'Connections', '/desktop/profiles');

    $this->get('/telemetry-ui/traces/'.str_repeat('a', 32))
        ->assertOk()
        ->assertSee('href="/desktop/profiles"', false);
});

it('escapes the label and url rather than trusting the host', function (): void {
    TelemetryUi::navLink('x', '<script>alert(1)</script>', '/a"onmouseover="alert(1)');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('onmouseover="alert(1)"', false);
});

it('draws only package-authored icon markup, whatever name the host passes', function (): void {
    $injected = new NavLink('x', 'X', '/x', '"/><script>alert(1)</script>');

    expect($injected->iconPath())
        ->not->toContain('script')
        ->toBe((new NavLink('y', 'Y', '/y', 'no-such-icon'))->iconPath());
});

it('resolves each documented icon to distinct markup', function (): void {
    $names = ['back', 'home', 'settings', 'connection', 'server', 'database', 'user'];

    $paths = array_map(fn (string $n): string => (new NavLink('k', 'L', '/u', $n))->iconPath(), $names);

    expect(array_unique($paths))->toHaveCount(count($names));
});

it('replaces a link registered twice under one key', function (): void {
    TelemetryUi::navLink('back', 'First', '/first');
    TelemetryUi::navLink('back', 'Second', '/second');

    expect(TelemetryUi::navLinks())->toHaveCount(1)
        ->and(TelemetryUi::navLinks()[0]->label)->toBe('Second');
});

it('removes a link', function (): void {
    TelemetryUi::navLink('back', 'Back', '/back');
    TelemetryUi::removeNavLink('back');

    expect(TelemetryUi::navLinks())->toBe([]);
});

it('registers none by default, so a plain host sees no foreign chrome', function (): void {
    expect(TelemetryUi::navLinks())->toBe([]);
});
