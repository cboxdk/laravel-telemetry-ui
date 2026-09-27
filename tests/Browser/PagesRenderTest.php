<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;

/**
 * Every registered page, rendered in a browser.
 *
 * This is the test the package was missing, and the reason it matters is
 * narrow: the API payload is built by `Panels\Ui` in PHP and consumed by
 * `resources/app/src/api/types.ts` in TypeScript, and CLAUDE.md says those
 * two mirror each other. Nothing enforced it. The PHP suite asserts the
 * payload's shape, the vitest suite asserts components handle props it
 * writes itself, and a field renamed on one side passed both.
 *
 * The list comes from the registry rather than a literal, so a page added
 * tomorrow is covered without anyone remembering to add it here.
 *
 * **Wait for the app before asserting anything.** The shell ships
 * "Loading dashboard…" and the assets load asynchronously, so a bare
 * `assertNoJavascriptErrors()` passes against a page where React never
 * booted — which is how the first version of this test reported all
 * thirty-eight pages clean while rendering none of them. `assertDontSee`
 * waits for its condition, and waiting for the loading state to go is what
 * makes everything after it mean something.
 */
it('renders every registered page without console errors', function (): void {
    $broken = [];

    foreach (array_keys(TelemetryUi::pages()) as $page) {
        $path = $page === 'dashboard' ? '/telemetry-ui' : "/telemetry-ui/p/{$page}";

        try {
            visit($path)
                ->assertDontSee('Loading dashboard')
                // The SPA answers an unknown route with its own "Page not
                // found", which renders perfectly cleanly — so without
                // this every wrong URL passed. The first version of this
                // test reported thirty-eight healthy pages while visiting
                // thirty-seven 404s.
                ->assertDontSee('Page not found')
                ->assertNoJavascriptErrors()
                ->assertNoConsoleLogs();
        } catch (Throwable $e) {
            $broken[$page] = trim(explode("\n", $e->getMessage())[0]);
        }
    }

    expect($broken)->toBe([], 'pages that did not render cleanly: '.json_encode($broken, JSON_PRETTY_PRINT));
});
