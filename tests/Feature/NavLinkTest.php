<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Support\NavLink;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('offers host links in bootstrap', function (): void {
    TelemetryUi::navLink('profiles', 'Connections', '/desktop/profiles', 'connection');
    TelemetryUi::navLink('home', 'Home', '/');

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('navLinks', [
            ['key' => 'profiles', 'label' => 'Connections', 'url' => '/desktop/profiles', 'icon' => 'connection'],
            ['key' => 'home', 'label' => 'Home', 'url' => '/', 'icon' => null],
        ]);
});

it('hands the label and url over as data, byte for byte', function (): void {
    TelemetryUi::navLink('x', '<script>alert(1)</script>', '/a"onmouseover="alert(1)');

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('navLinks.0.label', '<script>alert(1)</script>')
        ->assertJsonPath('navLinks.0.url', '/a"onmouseover="alert(1)');
});

it('offers no links by default', function (): void {
    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('navLinks', []);
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
