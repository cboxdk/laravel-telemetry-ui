<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Tests;

use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cards are lazy in the browser (the shell renders first, each card
        // streams in). Render them eagerly in tests so a full-page request
        // exercises the real card output and its backend queries.
        Livewire::withoutLazyLoading();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TelemetryUiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('telemetry-ui.connections.metrics.url', 'http://prometheus.test:9090');
        $app['config']->set('telemetry-ui.connections.traces.url', 'http://tempo.test:3200');
        $app['config']->set('telemetry-ui.connections.logs.url', 'http://loki.test:3100');
    }
}
