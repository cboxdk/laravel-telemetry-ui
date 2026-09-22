<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

/**
 * The boot JSON embedded in the SPA shell, decoded.
 *
 * @return array<string, mixed>
 */
function shellBoot(string $html): array
{
    preg_match('~<script type="application/json" id="telemetry-ui-boot">(.*?)</script>~s', $html, $m);

    $decoded = json_decode($m[1] ?? '', true);

    return is_array($decoded) ? $decoded : [];
}

it('white-labels the name, logo and accent colour', function (): void {
    config()->set('telemetry-ui.brand.name', 'Acme Observability');
    config()->set('telemetry-ui.brand.logo', '/img/acme.svg');
    config()->set('telemetry-ui.brand.accent', '#ff0066');

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('app.name', 'Acme Observability')
        ->assertJsonPath('app.logo', '/img/acme.svg')
        ->assertJsonPath('app.accent', '#ff0066');

    $html = (string) $this->get('/telemetry-ui')->assertOk()->assertSee('<title>Acme Observability</title>', false)->getContent();

    expect(shellBoot($html)['brand'])->toBe(['name' => 'Acme Observability', 'logo' => '/img/acme.svg', 'accent' => '#ff0066']);
});

it('defaults the brand when the host sets none', function (): void {
    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('app.name', 'Telemetry')
        ->assertJsonPath('app.logo', null)
        ->assertJsonPath('app.accent', null);

    $this->get('/telemetry-ui')->assertOk()->assertSee('<title>Telemetry</title>', false);
});

it('keeps functional accent values intact', function (string $accent): void {
    config()->set('telemetry-ui.brand.accent', $accent);

    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('app.accent', $accent);
})->with(['#ff0066', 'rgb(255, 0, 102)', 'oklch(0.65 0.18 258)', 'hsl(330 100% 50%)', 'rebeccapurple']);

it('sanitises the accent value, which ends up in a css custom property', function (): void {
    config()->set('telemetry-ui.brand.accent', 'red;} body{display:none'); // injection attempt

    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('app.accent', 'red bodydisplaynone');

    expect(shellBoot((string) $this->get('/telemetry-ui')->getContent())['brand']['accent'])->toBe('red bodydisplaynone');
});

it('blocks an external url() in the accent value', function (): void {
    config()->set('telemetry-ui.brand.accent', 'url(//evil.example/x)'); // exfil attempt

    $accent = $this->getJson(apiUrl('bootstrap'))->assertOk()->json('app.accent');

    // No slash or quote survives → no external url().
    expect($accent)->not->toContain('/')->not->toContain('url(//');
});

it('escapes the brand name in the shell title', function (): void {
    config()->set('telemetry-ui.brand.name', '<b>Acme</b> & Co');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('<title>&lt;b&gt;Acme&lt;/b&gt; &amp; Co</title>', false)
        ->assertDontSee('<title><b>', false);

    // Bootstrap JSON is data: the exact string round-trips.
    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('app.name', '<b>Acme</b> & Co');
});

it('cannot break out of the boot json script tag, whatever the brand name holds', function (): void {
    $name = 'Acme</script><script>alert(1)</script><!--';
    config()->set('telemetry-ui.brand.name', $name);

    $html = (string) $this->get('/telemetry-ui')->assertOk()->getContent();

    // Exactly the shell's own closing tags — none injected by the value.
    preg_match('~<script type="application/json" id="telemetry-ui-boot">(.*?)</script>~s', $html, $m);

    expect($m[1] ?? '')->not->toContain('</script')->not->toContain('<!--')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and(shellBoot($html)['brand']['name'])->toBe($name);
});
