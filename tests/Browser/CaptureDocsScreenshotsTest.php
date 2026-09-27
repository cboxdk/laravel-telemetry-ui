<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Testing\FixtureData;
use Cbox\TelemetryUi\Testing\ScreenshotManifest;

/**
 * Captures the documentation screenshots into `docs/screenshots/`.
 *
 * Off unless asked for, because it writes files the repository tracks and a
 * test that rewrites committed PNGs on every run makes every diff noise:
 *
 *   TELEMETRY_UI_CAPTURE_SCREENSHOTS=1 vendor/bin/pest tests/Browser/CaptureDocsScreenshotsTest.php
 *   TELEMETRY_UI_SCREENSHOT=dashboard TELEMETRY_UI_CAPTURE_SCREENSHOTS=1 vendor/bin/pest tests/Browser/CaptureDocsScreenshotsTest.php
 *
 * The data is the fixture backends, so a shot taken on a laptop with no
 * Tempo matches one taken in CI — which is the only reason committing them
 * is honest. See {@see FixtureData}.
 *
 * This test is also the strictest thing in the suite, deliberately. A page
 * render test passes on a screen whose panels are all empty states, because
 * an empty state is a legitimate screen and the backend reported no error —
 * which is how four blank Analytics panels survived 38 pages of render
 * assertions. A screenshot cannot be that forgiving: against a fixture whose
 * whole purpose is completeness, a blank panel is a hole in the fixture, and
 * publishing a picture of it documents the hole.
 */
it('captures the documentation screenshots', function (): void {
    $only = getenv('TELEMETRY_UI_SCREENSHOT') ?: null;
    $target = dirname(__DIR__, 2).'/docs/screenshots';

    if (! is_dir($target)) {
        mkdir($target, 0o755, true);
    }

    $captured = [];

    foreach (ScreenshotManifest::all() as $key => $shot) {
        if ($only !== null && $only !== $key) {
            continue;
        }

        // Every panel has resolved, and on a screen that charts, a chart has
        // actually been drawn rather than merely asked for.
        $drawn = 'document.querySelectorAll(".t-skeleton").length === 0'
            .($shot['charts'] ? ' && document.querySelectorAll("canvas").length > 0' : '');

        // Every assertion below retries until it holds or the browser
        // timeout expires, which is what makes them waits as well as
        // assertions: ECharts arrives as a lazily-loaded chunk and mounts its
        // canvas a frame or two after the panel's data lands, so a shot taken
        // the moment the page settles catches panels with their numbers drawn
        // and their chart areas blank.
        visit($shot['url'])
            ->resize($shot['width'], $shot['height'])
            // Same reason as the render test: the shell ships "Loading
            // dashboard…", so a screenshot taken without waiting is a
            // picture of a spinner.
            ->assertDontSee('Loading dashboard')
            // And not a picture of the 404: the SPA answers an unknown
            // route with its own "Page not found", which renders as
            // cleanly as anything else. Ten of the first eleven shots
            // here were that page.
            ->assertDontSee('Page not found')
            ->waitForEvent('networkidle')
            ->assertScript($drawn)
            // No panel fell back to an empty state, and none reported a
            // backend error. Both of those render cleanly and would be
            // committed as documentation without either suite complaining.
            ->assertScript('document.querySelectorAll(".t-empty").length === 0')
            ->assertScript('document.querySelectorAll(".t-state").length === 0')
            ->screenshot($shot['full'], $key);

        $written = dirname(__DIR__).'/Browser/Screenshots/'.$key.'.png';

        expect($written)->toBeFile();

        rename($written, $target.'/'.$key.'.png');
        $captured[] = $key;
    }

    expect($captured)->not->toBeEmpty();

    fwrite(STDERR, sprintf(
        "\ncaptured %d screenshot(s) into docs/screenshots: %s\n",
        count($captured),
        implode(', ', $captured),
    ));
})->skip(
    fn (): bool => getenv('TELEMETRY_UI_CAPTURE_SCREENSHOTS') !== '1',
    'set TELEMETRY_UI_CAPTURE_SCREENSHOTS=1 to rewrite the committed PNGs',
);
