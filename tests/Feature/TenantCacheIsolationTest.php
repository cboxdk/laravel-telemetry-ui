<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\StreamLabels;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Two tenants behind one host app, each with their own backends, read in
 * turn through one shared cache store (the array store stands in for the
 * Redis/Valkey every worker shares). Nothing one tenant's request cached may
 * be served to the other's.
 */

/**
 * Point the per-viewer resolver at tenant-<name>.test, switchable between
 * "requests" through the returned setter.
 *
 * @return Closure(string): void
 */
function twoTenants(): Closure
{
    $tenant = 'a';

    app(TelemetryUiManager::class)->resolveConnectionsUsing(function ($user) use (&$tenant): array {
        return [
            'metrics' => ['driver' => 'prometheus', 'url' => "http://tenant-{$tenant}.test:9090"],
            'traces' => ['driver' => 'tempo', 'url' => "http://tenant-{$tenant}.test:3200"],
            'logs' => ['driver' => 'loki', 'url' => "http://tenant-{$tenant}.test:3100", 'tenant' => "org-{$tenant}"],
        ];
    });

    // Each backend answers with its own tenant's name in everything it says.
    Http::fake(function (Request $request) {
        $tenant = str_contains($request->url(), 'tenant-a.test') ? 'a' : 'b';
        $url = $request->url();

        return match (true) {
            str_contains($url, '/api/v1/label/__name__/values') => Http::response(['status' => 'success', 'data' => $tenant === 'a' ? ['horizon_jobs_total'] : []]),
            str_contains($url, '/api/v1/label/') => Http::response(['status' => 'success', 'data' => ["svc-{$tenant}"]]),
            str_contains($url, '/loki/api/v1/labels') => Http::response(['status' => 'success', 'data' => $tenant === 'a' ? ['service_name', 'deployment_environment_name'] : ['service_name']]),
            str_contains($url, '/loki/api/v1/query_range') => Http::response(lokiStreams([[
                'stream' => ['service_name' => "svc-{$tenant}", 'deployment_id' => "{$tenant}-1"],
                'values' => [[(string) ((time() - 60) * 1_000_000_000), 'app.deployment']],
            ]])),
            default => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        };
    });

    return function (string $next) use (&$tenant): void {
        app()->forgetScopedInstances(); // the request boundary, as under Octane
        $tenant = $next;
    };
}

beforeEach(function (): void {
    $this->actingAs(new GenericUser(['id' => 1]));
});

it('gives each tenant its own fingerprint, without exposing a credential', function (): void {
    $switch = twoTenants();
    $connections = app(ConnectionManager::class);

    $a = $connections->fingerprint();
    $switch('b');
    $b = $connections->fingerprint();

    expect($a)->not->toBe($b)
        ->and($a)->toMatch('/^[0-9a-f]{32}$/')
        ->and($connections->cacheKey('fleet:service_name'))->toBe("telemetry-ui:{$b}:fleet:service_name");

    $switch('a');
    expect($connections->fingerprint())->toBe($a);
});

it('tells apart two viewers on one backend with different credentials', function (): void {
    $token = 'secret-one';

    app(TelemetryUiManager::class)->resolveConnectionsUsing(function ($user) use (&$token): array {
        return ['metrics' => ['driver' => 'prometheus', 'url' => 'http://shared.test:9090', 'token' => $token]];
    });

    $connections = app(ConnectionManager::class);
    $one = $connections->fingerprint();

    app()->forgetScopedInstances();
    $token = 'secret-two';

    expect($connections->fingerprint())->not->toBe($one)
        ->and($connections->cacheKey('x'))->not->toContain('secret');
});

it('never serves one tenant the fleet another tenant discovered', function (): void {
    $switch = twoTenants();

    expect(app(Fleet::class)->services())->toBe(['svc-a']);

    $switch('b');
    expect(app(Fleet::class)->services())->toBe(['svc-b']);

    // And the warm cache still answers each from its own entry.
    $switch('a');
    expect(app(Fleet::class)->services())->toBe(['svc-a']);
});

it('never serves one tenant another tenant\'s deploy markers', function (): void {
    $switch = twoTenants();
    $labels = static fn (): array => array_map(static fn (Annotation $a): string => $a->label, app(Annotations::class)->lookback());

    expect($labels())->toBe(['Deploy a-1']);

    $switch('b');
    expect($labels())->toBe(['Deploy b-1']);
});

it('never serves one tenant another tenant\'s page detection', function (): void {
    $switch = twoTenants();

    expect(app(SchemaDetector::class)->hasMetricsMatching('horizon_.*'))->toBeTrue();

    $switch('b');
    expect(app(SchemaDetector::class)->hasMetricsMatching('horizon_.*'))->toBeFalse();
});

it('never serves one tenant another tenant\'s stream labels', function (): void {
    $switch = twoTenants();

    expect(app(StreamLabels::class)->indexes('deployment_environment_name'))->toBeTrue();

    $switch('b');
    expect(app(StreamLabels::class)->indexes('deployment_environment_name'))->toBeFalse();
});

it('never serves one tenant another tenant\'s infrastructure map', function (): void {
    $switch = twoTenants();
    $discoverer = app(Discoverer::class);

    $discoverer->discover();
    $sentToA = count(Http::recorded());

    // Tenant b has no map of its own yet, so it must discover against its
    // own backends rather than read tenant a's.
    $switch('b');
    $discoverer->map();

    $sentToB = collect(Http::recorded())->skip($sentToA)->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'tenant-b.test'))->count();

    expect($sentToB)->toBeGreaterThan(0);
});

it('never serves one tenant a display name another tenant resolved', function (): void {
    $switch = twoTenants();
    $tenant = 'a';

    TelemetryUi::dimension('billing.customer_id', label: 'Customer', resolve: function (array $ids) use (&$tenant): array {
        return array_fill_keys($ids, "Customer of {$tenant}");
    });

    $url = '/telemetry-ui/api/v2/dimensions/labels?'.http_build_query(['key' => 'billing.customer_id', 'values' => ['17']]);

    $this->getJson($url)->assertExactJson(['labels' => ['17' => 'Customer of a']]);

    $switch('b');
    $tenant = 'b';
    $this->getJson($url)->assertExactJson(['labels' => ['17' => 'Customer of b']]);
});

it('keys the query cache on every header, not only Authorization', function (): void {
    config()->set('telemetry-ui.cache.ttl', 60);
    $org = 'one';

    app(TelemetryUiManager::class)->resolveConnectionsUsing(function ($user) use (&$org): array {
        return ['metrics' => ['driver' => 'prometheus', 'url' => 'http://shared.test:9090', 'headers' => ['X-Org' => $org]]];
    });

    Http::fake(fn (Request $request) => Http::response(labelledVector([[['org' => $request->header('X-Org')[0] ?? ''], 1]])));

    $read = static fn (): string => (string) (app(ConnectionManager::class)->metrics()->query(MetricQuery::raw('up'))[0]->labels['org'] ?? '');

    expect($read())->toBe('one');

    app()->forgetScopedInstances();
    $org = 'two';

    expect($read())->toBe('two');
});
