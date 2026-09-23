<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Gate;

/**
 * Put a file under public/build for the duration of a test. A real build may
 * live there (and a concurrent `vite build` may be writing it), so an
 * existing file is restored byte for byte afterwards, and only directories
 * this helper created are removed.
 *
 * @return Closure(): void the cleanup
 */
function withBuildFile(string $relative, string $contents): Closure
{
    $root = AssetController::root();
    $path = $root.'/'.$relative;
    $original = is_file($path) ? file_get_contents($path) : null;
    $created = [];

    for ($dir = dirname($path); ! is_dir($dir); $dir = dirname($dir)) {
        array_unshift($created, $dir);
    }

    foreach ($created as $dir) {
        mkdir($dir);
    }

    file_put_contents($path, $contents);

    return static function () use ($path, $created, $original): void {
        if (is_string($original)) {
            file_put_contents($path, $original);

            return;
        }

        @unlink($path);

        foreach (array_reverse($created) as $dir) {
            @rmdir($dir); // only if empty — never touches a real build
        }
    };
}

it('answers every dashboard path with the spa shell', function (string $path): void {
    $this->get($path)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('<script type="application/json" id="telemetry-ui-boot">', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
})->with([
    '/telemetry-ui',
    '/telemetry-ui/',
    '/telemetry-ui/explore/requests',
    '/telemetry-ui/entities/route?value=/x',
    '/telemetry-ui/p/statamic-cache',
    '/telemetry-ui/errors/abc123def456?drawer=trace:abc',
]);

it('does not swallow unknown api routes', function (): void {
    $response = $this->getJson('/telemetry-ui/api/v2/unknown')->assertNotFound();

    expect((string) $response->getContent())->not->toContain('telemetry-ui-boot');

    $this->get('/telemetry-ui/api/v2/pages/Not_A_Slug')->assertNotFound();
});

it('points the boot json at the configured mount path', function (): void {
    $html = (string) $this->get('/telemetry-ui/explore/logs')->getContent();

    preg_match('~id="telemetry-ui-boot">(.*?)</script>~s', $html, $m);
    $boot = json_decode($m[1] ?? '', true);

    expect($boot)->toMatchArray([
        'base' => '/telemetry-ui',
        'api' => '/telemetry-ui/api/v2',
        'assets' => '/telemetry-ui/build',
    ]);
});

it('links the entry chunk and its css from the vite manifest', function (): void {
    $cleanups = [
        withBuildFile('.vite/manifest.json', (string) json_encode([
            'index.html' => ['file' => 'assets/index-abc123.js', 'css' => ['assets/index-def456.css'], 'isEntry' => true],
        ])),
    ];

    try {
        $this->get('/telemetry-ui')
            ->assertOk()
            ->assertSee('<script type="module" src="/telemetry-ui/build/assets/index-abc123.js"></script>', false)
            ->assertSee('<link rel="stylesheet" href="/telemetry-ui/build/assets/index-def456.css">', false)
            ->assertDontSee('the SPA build is missing');
    } finally {
        array_walk($cleanups, fn (Closure $c) => $c());
    }
});

it('explains a missing build instead of rendering a blank page', function (): void {
    if (is_file(AssetController::root().'/.vite/manifest.json')) {
        $this->markTestSkipped('A real build is present.');
    }

    $this->get('/telemetry-ui')->assertOk()->assertSee('the SPA build is missing');
});

it('serves a built asset, immutable and typed, without the gate', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => false);

    $cleanup = withBuildFile('assets/tui-test-7f3a.js', 'console.log("hi")');

    try {
        $response = $this->get('/telemetry-ui/build/assets/tui-test-7f3a.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')
            ->and($response->baseResponse->getFile()->getContent())->toBe('console.log("hi")');
    } finally {
        $cleanup();
    }
});

it('refuses path traversal, unknown types and missing files', function (string $path): void {
    $cleanup = withBuildFile('assets/tui-secret-7f3a.php', '<?php echo 1;');

    try {
        $this->get('/telemetry-ui/build/'.$path)->assertNotFound();
    } finally {
        $cleanup();
    }
})->with([
    'dot-dot' => ['../../composer.json'],
    'encoded dot-dot' => ['assets/..%2F..%2F..%2Fcomposer.json'],
    'unserved extension' => ['assets/tui-secret-7f3a.php'],
    'missing' => ['assets/nope-000.js'],
]);
