<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/*
 * "All services" on a log read: the services the fleet discovered, not every
 * stream the log store holds. A shared Loki also keeps load balancer, host and
 * container logs, often far more than the apps write, and an unnarrowed
 * selector makes it read all of them for records only the apps emit.
 */

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);
});

/**
 * A metrics store whose service label values are $services (null: a 500), and
 * an empty answer from every other backend.
 *
 * @param  list<string>|null  $services
 */
function fleetOf(?array $services): void
{
    Http::fake(function (Request $request) use ($services) {
        $url = $request->url();

        if (str_contains($url, 'prometheus.test') && str_contains($url, '/label/service_name/values')) {
            return $services === null
                ? Http::response('unavailable', 500)
                : Http::response(['status' => 'success', 'data' => $services]);
        }

        return match (true) {
            str_contains($url, 'prometheus.test') => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
            str_contains($url, 'tempo.test') => Http::response(['traces' => []]),
            default => Http::response(lokiStreams([])),
        };
    });
}

/**
 * The stream selector of every LogQL query sent.
 *
 * @return list<string>
 */
function sentSelectors(): array
{
    return array_map(static fn (string $q): string => preg_match('/^\{[^}]*\}/', $q, $m) === 1 ? $m[0] : $q, sentLogql());
}

it('reads only the discovered services\' logs with all services selected', function (string $path): void {
    fleetOf(['cbox-web', 'billing']);

    $this->getJson($path)->assertOk();

    expect(sentSelectors())->not->toBeEmpty()->each->toBe('{service_name=~"billing|cbox-web"}');
})->with([
    'the log explorer' => fn (): string => apiUrl('explore/logs'),
    'the error explorer' => fn (): string => apiUrl('explore/errors'),
    'a panel that reads log records' => fn (): string => panelUrl('n-plus-one-queries'),
    'a page view panel' => fn (): string => panelUrl('page-view-log'),
]);

it('reads only the selected service when one is selected', function (): void {
    fleetOf(['cbox-web', 'billing']);

    $this->getJson(apiUrl('explore/logs', ['service' => 'billing']))->assertOk();

    expect(sentSelectors())->toBe(['{service_name="billing"}']);
});

it('reads any stream when discovery finds no services', function (): void {
    fleetOf([]);

    $this->getJson(apiUrl('explore/logs'))->assertOk();

    expect(sentSelectors())->toBe(['{service_name=~".+"}']);
});

it('reads any stream when the metrics store cannot list services, and still answers', function (): void {
    fleetOf(null);

    $this->getJson(panelUrl('n-plus-one-queries'))->assertOk()->assertJsonMissingPath('error');

    expect(sentSelectors())->not->toBeEmpty()->each->toBe('{service_name=~".+"}');
});

it('reads any stream when the fleet does not scope logs', function (): void {
    config()->set('telemetry-ui.fleet.scopes_logs', false);
    fleetOf(['cbox-web', 'billing']);

    $this->getJson(apiUrl('explore/logs'))->assertOk();

    expect(sentSelectors())->toBe(['{service_name=~".+"}']);
});

it('matches a discovered service name literally', function (): void {
    fleetOf(['api.v2', 'web']);

    $this->getJson(apiUrl('explore/logs'))->assertOk();

    expect(sentSelectors())->toBe(['{service_name=~"api\\\\.v2|web"}']);
});

it('keeps a service lock ahead of the fleet', function (): void {
    fleetOf(['cbox-web', 'billing', 'internal']);
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['cbox-web']]);

    $this->getJson(apiUrl('explore/logs'))->assertOk();

    expect(sentSelectors())->toBe(['{service_name="cbox-web"}']);
});

it('keeps a lock to no services closed', function (): void {
    fleetOf(['cbox-web', 'billing']);
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => []]);

    $this->getJson(apiUrl('explore/logs'))->assertOk();

    expect(sentSelectors())->each->toBe('{service_name="__telemetry_ui_no_scope__"}');
});
