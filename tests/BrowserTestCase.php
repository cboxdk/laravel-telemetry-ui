<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Tests;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Testing\FixtureData;
use Cbox\TelemetryUi\Testing\FixtureLogs;
use Cbox\TelemetryUi\Testing\FixtureMetrics;
use Cbox\TelemetryUi\Testing\FixtureTraces;

/**
 * The base case for tests that render the dashboard in a real browser.
 *
 * This package had none, which is a strange thing for a UI package to be
 * able to say: 708 PHP tests covering the API and the panels, 87 vitest
 * specs covering components and formatting, and not one that started the
 * app, let React boot, called `/api/v2` and looked at what came out. The
 * two halves were each tested thoroughly and never together — so a broken
 * contract between `Panels\Ui` and `resources/app/src/api/types.ts`, the
 * two files CLAUDE.md says mirror each other, passed both suites.
 *
 * The backends are the fixture sources, so a run needs no Tempo, no Loki,
 * no Prometheus and no credentials, and renders the same screen every time.
 * See {@see FixtureData}.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The SPA is served from the committed build, so a browser run does
        // not depend on a Vite dev server being up.
        // Three driver names rather than one, because MetricsSource and
        // LogsSource both declare `query()` with different signatures — no
        // single class can implement both, and the real drivers are three
        // classes for the same reason.
        $app['config']->set('telemetry-ui.connections.metrics', ['driver' => 'fixture-metrics']);
        $app['config']->set('telemetry-ui.connections.traces', ['driver' => 'fixture-traces']);
        $app['config']->set('telemetry-ui.connections.logs', ['driver' => 'fixture-logs']);

        // Deterministic screens: the query cache off means every page renders
        // from the fixture rather than from whatever a previous test left.
        $app['config']->set('telemetry-ui.cache.ttl', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connections = $this->app->make(ConnectionManager::class);

        $connections->extend('fixture-metrics', static fn (array $config): object => new FixtureMetrics);
        $connections->extend('fixture-traces', static fn (array $config): object => new FixtureTraces);
        $connections->extend('fixture-logs', static fn (array $config): object => new FixtureLogs);
    }
}
