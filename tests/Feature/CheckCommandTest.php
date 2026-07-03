<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

function fakeHealthyBackends(): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => [], 'value' => [1735689600, '1']],
            ]],
        ]),
        'tempo.test:3200/api/v2/search/tag/*' => Http::response([
            'tagValues' => [['type' => 'string', 'value' => 'checkout']],
        ]),
        'loki.test:3100/loki/api/v1/label/*' => Http::response([
            'status' => 'success',
            'data' => ['billing', 'checkout'],
        ]),
    ]);
}

it('reports every configured connection as reachable', function (): void {
    fakeHealthyBackends();

    $this->artisan('telemetry-ui:check')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK')
        ->expectsOutputToContain('All configured connections are reachable.');
});

it('marks an unconfigured connection instead of probing it', function (): void {
    fakeHealthyBackends();

    // issues has no driver by default → not configured, not a failure.
    $this->artisan('telemetry-ui:check')
        ->expectsOutputToContain('not configured')
        ->assertExitCode(0);
});

it('fails when a backend is unreachable', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('upstream down', 502)]);

    $this->artisan('telemetry-ui:check --connection=metrics')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

it('probes only the named connection', function (): void {
    fakeHealthyBackends();

    $this->artisan('telemetry-ui:check --connection=logs')
        ->assertExitCode(0);

    // Only Loki was touched; metrics/traces were skipped.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'loki.test'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'prometheus.test'));
});

it('probes a configured issues tracker', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_test',
    ]);

    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues*' => Http::response([
            ['number' => 1, 'title' => 'x', 'state' => 'open', 'html_url' => 'https://github.com/x/1'],
        ]),
    ]);

    $this->artisan('telemetry-ui:check --connection=issues')
        ->expectsOutputToContain('OK')
        ->assertExitCode(0);
});
