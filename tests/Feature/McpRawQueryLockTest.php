<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\QueryLogsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryMetricsTool;
use Cbox\TelemetryUi\Mcp\Tools\QueryRangeTool;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

/*
 * Raw PromQL and LogQL name their own selectors and cannot be confined to a
 * scope lock with certainty, so under a lock the raw tools refuse instead of
 * running a query that reads every tenant's series.
 */

beforeEach(function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);
});

it('refuses a raw query under a scope lock without asking the backend', function (string $tool, array $arguments): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);

    TelemetryServer::tool($tool, $arguments)
        ->assertHasErrors()
        ->assertSee('scope lock');

    Http::assertNothingSent();
})->with([
    'query_metrics' => [QueryMetricsTool::class, ['query' => 'up']],
    'query_range' => [QueryRangeTool::class, ['query' => 'up', 'minutes' => 5]],
    'query_logs' => [QueryLogsTool::class, ['query' => '{service_name="billing"}']],
]);

it('refuses under an environment-only lock too', function (): void {
    config()->set('telemetry-ui.scope.lock', ['environments' => ['production']]);

    TelemetryServer::tool(QueryMetricsTool::class, ['query' => 'up'])->assertHasErrors();

    Http::assertNothingSent();
});

it('runs a raw query when no lock is active', function (): void {
    TelemetryServer::tool(QueryMetricsTool::class, ['query' => 'up'])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'prometheus.test'));
});
