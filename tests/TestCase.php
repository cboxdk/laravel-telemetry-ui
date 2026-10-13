<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Tests;

use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The default gate is local-only; tests exercise the dashboard as an
        // allowed viewer unless they redefine it (AuthTest does).
        Gate::define('viewTelemetryUi', static fn (?object $user = null, ?string $page = null): bool => true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            // laravel/mcp's provider hands a tool call's arguments to the
            // tool's Request; without it every MCP tool test ran with none.
            McpServiceProvider::class,
            TelemetryUiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        // Query cache off by default so per-request HTTP assertions are
        // deterministic; tests that exercise the cache opt in explicitly.
        $app['config']->set('telemetry-ui.cache.ttl', 0);
        $app['config']->set('telemetry-ui.connections.metrics.url', 'http://prometheus.test:9090');
        $app['config']->set('telemetry-ui.connections.traces.url', 'http://tempo.test:3200');
        $app['config']->set('telemetry-ui.connections.logs.url', 'http://loki.test:3100');
    }
}
