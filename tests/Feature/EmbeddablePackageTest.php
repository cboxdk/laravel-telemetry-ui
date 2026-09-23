<?php

declare(strict_types=1);

/**
 * The React components ship inside the composer package so a host can install
 * them straight out of vendor/ — these are the facts that makes true.
 */
function appPackage(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../resources/app/package.json'), true);
}

it('ships the components as an npm package a host can install from vendor', function (): void {
    $package = appPackage();

    expect($package['name'])->toBe('@cboxdk/telemetry-ui')
        ->and($package['exports']['.'])->toBe('./src/embed/index.tsx')
        ->and($package['exports']['./styles.css'])->toBe('./src/styles/embed.css')
        ->and($package['exports']['./fonts.css'])->toBe('./src/styles/fonts.css')
        ->and($package['peerDependencies'])->toHaveKeys(['react', 'react-dom'])
        // React is the host's, not ours — two copies break hooks.
        ->and($package['dependencies'] ?? [])->not->toHaveKey('react');

    foreach (['./src/embed/index.tsx', './src/styles/embed.css', './src/styles/app.css', './src/styles/tokens.css', './src/styles/fonts.css'] as $file) {
        expect(__DIR__.'/../../resources/app/'.ltrim($file, './'))->toBeReadableFile();
    }
});

it('asks the host for the same dependency versions the dashboard is built with', function (): void {
    $root = json_decode((string) file_get_contents(__DIR__.'/../../package.json'), true);
    $app = appPackage();

    foreach ($app['dependencies'] as $name => $constraint) {
        expect($constraint)->toBe($root['dependencies'][$name] ?? null, "{$name} drifted between the build and the published package");
    }
});

it('keeps the components\' stylesheet free of document-level rules', function (): void {
    // Embedded, the host owns <body>: those rules live in shell.css instead.
    $css = (string) file_get_contents(__DIR__.'/../../resources/app/src/styles/app.css');
    $selectors = [];

    foreach (explode("\n", $css) as $line) {
        if (preg_match('/^([^@\s.:\[][^{]*)\{/', $line, $m) === 1) {
            $selectors[] = trim($m[1]);
        }
    }

    // An element may be styled (a.t-bar, button.t-rstat) as long as every part
    // of the selector is anchored to one of our own classes.
    $unscoped = [];

    foreach ($selectors as $selector) {
        foreach (explode(',', $selector) as $part) {
            if (! str_contains($part, '.t-')) {
                $unscoped[] = trim($part);
            }
        }
    }

    expect($unscoped)->toBe([]);

    expect(file_get_contents(__DIR__.'/../../resources/app/src/main.tsx'))->toContain('styles/shell.css');
});

it('keeps the component source in the dist tarball, minus its tests', function (): void {
    $attributes = (string) file_get_contents(__DIR__.'/../../.gitattributes');

    expect($attributes)->not->toContain('/resources/app      export-ignore')
        ->and($attributes)->toContain('/resources/app/src/test')
        ->and($attributes)->toContain('/resources/app/src/**/*.test.tsx');
});
