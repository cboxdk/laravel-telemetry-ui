<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => ['cbox-web', 'billing', 'internal']]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
});

function lockScope(array $scope): void
{
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => $scope);
}

/**
 * Prometheus card queries that carry a service scope (excludes the schema-detect
 * count query and Loki queries, which legitimately have none).
 *
 * @return list<string>
 */
function scopedPromQueries(): array
{
    $queries = [];
    foreach (Http::recorded() as [$request]) {
        if (! str_contains($request->url(), 'prometheus.test')) {
            continue;
        }
        $q = requestQuery($request)['query'] ?? null;
        if (is_string($q) && str_contains(rawurldecode($q), 'service_name')) {
            $queries[] = rawurldecode($q);
        }
    }

    return $queries;
}

it('forces a blank selection into the locked service', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->get('/telemetry-ui/requests')->assertOk(); // no ?service=

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="cbox-web"');
});

it('coerces an out-of-bounds ?service= back to the lock', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->get('/telemetry-ui/requests?service=internal')->assertOk(); // not allowed

    $queries = scopedPromQueries();
    expect($queries)->not->toBeEmpty()->each->toContain('service_name="cbox-web"');
    foreach ($queries as $q) {
        expect($q)->not->toContain('service_name="internal"');
    }
});

it('honours a selection that is within the lock', function (): void {
    lockScope(['services' => ['cbox-web', 'billing']]);

    $this->get('/telemetry-ui/requests?service=billing')->assertOk();

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="billing"');
});

it('scopes to an RE2 alternation for a multi-service lock with no selection', function (): void {
    lockScope(['services' => ['web-a', 'web-b']]);

    $this->get('/telemetry-ui/requests')->assertOk();

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name=~"web-a|web-b"');
});

it('applies the lock to TraceQL scope too', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->get('/telemetry-ui/users')->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        return str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'resource.service.name = "cbox-web"');
    });
});

it('filters the fleet switcher to the allowed services', function (): void {
    lockScope(['services' => ['cbox-web']]);

    expect(app(Fleet::class)->services())->toBe(['cbox-web']); // billing / internal hidden
});

it('leaves the fleet unrestricted when no lock is set', function (): void {
    expect(app(Fleet::class)->services())->toContain('billing')->toContain('internal');
});
