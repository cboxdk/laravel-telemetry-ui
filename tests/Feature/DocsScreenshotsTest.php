<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Testing\ScreenshotManifest;

/**
 * The manifest says it is the one place a screen that changes gets updated,
 * and that "a shot nobody references shows up as an unused key rather than a
 * stale PNG nobody notices". Nothing made that true: six of the eleven
 * committed screenshots were referenced by no page at all, and the capture
 * test happily rewrote them on every run.
 *
 * These are cheap string assertions over the docs tree, not a renderer — they
 * only check that the manifest, the committed PNGs and the pages that embed
 * them agree with each other.
 */
function docsMarkdown(): array
{
    $root = dirname(__DIR__, 2).'/docs';
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('commits a PNG for every screen the manifest names', function (): void {
    $dir = dirname(__DIR__, 2).'/docs/screenshots';

    foreach (ScreenshotManifest::all() as $key => $shot) {
        expect($dir.'/'.$key.'.png')->toBeFile("docs/screenshots/{$key}.png is missing — recapture it");
    }
});

it('keeps no screenshot the manifest does not name', function (): void {
    $known = array_keys(ScreenshotManifest::all());
    $found = array_map(
        static fn (string $path): string => basename($path, '.png'),
        glob(dirname(__DIR__, 2).'/docs/screenshots/*.png') ?: [],
    );

    expect(array_values(array_diff($found, $known)))->toBe([]);
});

it('references every screenshot from a page that describes it', function (): void {
    $docs = docsMarkdown();

    foreach (ScreenshotManifest::all() as $key => $shot) {
        $referencing = array_keys(array_filter(
            $docs,
            static fn (string $body): bool => str_contains($body, "screenshots/{$key}.png"),
        ));

        expect($referencing)->not->toBeEmpty(
            "docs/screenshots/{$key}.png is committed but no page embeds it — "
            .'reference it, or drop the entry from ScreenshotManifest',
        );
    }
});

it('captions each screenshot with the manifest text', function (): void {
    foreach (docsMarkdown() as $path => $body) {
        preg_match_all('/!\[([^\]]*)\]\([^)]*screenshots\/([a-z0-9-]+)\.png\)/', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $caption, $key]) {
            $shot = ScreenshotManifest::all()[$key] ?? null;

            expect($shot)->not->toBeNull(basename($path)." embeds an unknown screenshot [{$key}]");
            // The manifest carries "the caption the docs will use", so a
            // caption edited in one place and not the other is a manifest
            // that has stopped being the source of truth it claims to be.
            expect($caption)->toBe($shot['caption'], basename($path)." caption for [{$key}] has drifted from the manifest");
        }
    }
});

it('points every screenshot link at a file that exists', function (): void {
    foreach (docsMarkdown() as $path => $body) {
        preg_match_all('/!\[[^\]]*\]\(([^)]*screenshots\/[a-z0-9-]+\.png)\)/', $body, $matches);

        foreach ($matches[1] as $href) {
            $resolved = realpath(dirname($path).'/'.$href);

            expect($resolved)->not->toBeFalse(basename($path)." links to [{$href}], which does not resolve");
        }
    }
});
