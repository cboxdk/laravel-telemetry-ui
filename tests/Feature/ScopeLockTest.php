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

    $this->getJson(panelUrl('requests-activity'))->assertOk(); // no ?service=

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="cbox-web"');
});

it('coerces an out-of-bounds ?service= back to the lock', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(panelUrl('requests-activity', ['service' => 'internal']))->assertOk(); // not allowed

    $queries = scopedPromQueries();
    expect($queries)->not->toBeEmpty()->each->toContain('service_name="cbox-web"');
    foreach ($queries as $q) {
        expect($q)->not->toContain('service_name="internal"');
    }
});

it('honours a selection that is within the lock', function (): void {
    lockScope(['services' => ['cbox-web', 'billing']]);

    $this->getJson(panelUrl('requests-activity', ['service' => 'billing']))->assertOk();

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="billing"');
});

it('scopes to an RE2 alternation for a multi-service lock with no selection', function (): void {
    lockScope(['services' => ['web-a', 'web-b']]);

    $this->getJson(panelUrl('requests-activity'))->assertOk();

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name=~"web-a|web-b"');
});

it('applies the lock to TraceQL scope too', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(panelUrl('traffic-by-facet'))->assertOk();

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

it('fails closed when a viewer is locked to no services', function (): void {
    lockScope(['services' => []]); // explicitly allowed nothing

    $this->getJson(panelUrl('requests-activity'))->assertOk();

    // Queries must match nothing, never widen to the whole fleet.
    $queries = scopedPromQueries();
    expect($queries)->not->toBeEmpty()->each->toContain('service_name="__telemetry_ui_no_scope__"');
    expect(app(Fleet::class)->services())->toBe([]); // empty switcher, not the full fleet
});

it('leaves environments unrestricted when the resolver only locks services', function (): void {
    lockScope(['services' => ['cbox-web']]); // no 'environments' key

    $this->getJson(panelUrl('requests-activity'))->assertOk();

    foreach (scopedPromQueries() as $q) {
        expect($q)->not->toContain('deployment_environment_name'); // env dimension stays open
    }
});

it('forces the lock into a raw ?q= trace query', function (): void {
    lockScope(['services' => ['cbox-web']]);

    // A hand-edited / deep-linked raw TraceQL must still be constrained.
    $this->getJson(panelUrl('trace-search', ['q' => '{ span.http.route = "/admin" }']))->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'resource.service.name = "cbox-web"') && str_contains($q, 'span.http.route = "/admin"');
    });
});

it('does not constrain a raw ?q= when no lock is active', function (): void {
    // Advanced raw queries stay unscoped without a lock (an escape hatch, not a
    // boundary) — a plain selection is a convenience filter only.
    $this->getJson(panelUrl('trace-search', ['q' => '{ span.http.route = "/admin" }']))->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }

        return ! str_contains(rawurldecode(requestQuery($request)['q'] ?? ''), 'resource.service.name');
    });
});

it('falls back to the allowed set when discovery is empty for a locked viewer', function (): void {
    // Simulate a discovery blip: the fleet label lookup came back empty.
    cache()->store()->put('telemetry-ui:fleet:service_name', [], 60);
    lockScope(['services' => ['cbox-web', 'billing']]);

    // Switcher offers the lock's own set rather than rendering empty.
    expect(app(Fleet::class)->services())->toBe(['cbox-web', 'billing']);
});

it('locks scope from config when no resolver is set', function (): void {
    config()->set('telemetry-ui.scope.lock.services', ['billing']);

    $this->getJson(panelUrl('requests-activity'))->assertOk(); // no ?service=, no hook

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="billing"');
    expect(app(Fleet::class)->services())->toBe(['billing']); // switcher constrained too
});

it('lets a restrictScopeUsing closure take precedence over the config lock', function (): void {
    config()->set('telemetry-ui.scope.lock.services', ['billing']);
    lockScope(['services' => ['cbox-web']]); // the dynamic hook wins

    $this->getJson(panelUrl('requests-activity'))->assertOk();

    expect(scopedPromQueries())->not->toBeEmpty()->each->toContain('service_name="cbox-web"');
});

it('treats an empty config lock as no lock', function (): void {
    config()->set('telemetry-ui.scope.lock.services', null); // the default

    $this->getJson(panelUrl('requests-activity'))->assertOk();

    // No lock → the full fleet, no forced service matcher.
    expect(app(Fleet::class)->services())->toContain('billing')->toContain('internal');
});

it('reports the locks and the allowed options in bootstrap', function (): void {
    lockScope(['services' => ['cbox-web'], 'environments' => ['production', 'staging']]);

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('scope.services', ['cbox-web'])
        ->assertJsonPath('scope.servicesLocked', true)
        ->assertJsonPath('scope.environmentsLocked', true);
});

it('reports no locks in bootstrap when none is set', function (): void {
    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('scope.services', ['billing', 'cbox-web', 'internal'])
        ->assertJsonPath('scope.servicesLocked', false)
        ->assertJsonPath('scope.environmentsLocked', false);
});

it('forces the lock into explore span searches, whatever ?service= says', function (string $signal): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(apiUrl('explore/'.$signal, ['service' => 'internal', 'where' => ['http.route=/admin']]))->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }
        $q = requestQuery($request)['q'] ?? '';

        return str_contains($q, 'resource.service.name = "cbox-web"')
            && ! str_contains($q, 'internal')
            && str_contains($q, 'span.http.route = "/admin"');
    });
})->with(['requests', 'traces']);

it('forces the lock into explore log and error queries', function (string $signal): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(apiUrl('explore/'.$signal, ['service' => 'internal']))->assertOk();

    $sent = collect(Http::recorded())
        ->map(fn (array $pair): string => (string) (requestQuery($pair[0])['query'] ?? ''))
        ->filter(fn (string $q): bool => str_starts_with($q, '{'))
        ->values();

    expect($sent)->not->toBeEmpty()->each->toStartWith('{service_name="cbox-web"}');
    expect($sent->implode(' '))->not->toContain('internal');
})->with(['logs', 'errors']);

it('fails closed in explore when locked to no services', function (): void {
    lockScope(['services' => []]);

    $this->getJson(apiUrl('explore/requests'))->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search')
        && str_contains(requestQuery($request)['q'] ?? '', 'resource.service.name = "__telemetry_ui_no_scope__"'));
});

it('forces the lock into entity index and story queries', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(apiUrl('entities/route', ['service' => 'internal']))->assertOk();
    $this->getJson(apiUrl('entities/route/story', ['value' => '/orders', 'service' => 'internal']))->assertOk();

    $queries = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/api/search') || str_contains($pair[0]->url(), 'loki.test'))
        ->map(fn (array $pair): string => (string) (requestQuery($pair[0])['q'] ?? requestQuery($pair[0])['query'] ?? ''))
        ->values();

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $q) {
        expect($q)->not->toContain('internal')
            ->and(str_contains($q, 'resource.service.name = "cbox-web"') || str_contains($q, 'service_name="cbox-web"'))->toBeTrue($q);
    }
});

it('forces the lock into facet queries', function (): void {
    lockScope(['services' => ['cbox-web']]);

    $this->getJson(apiUrl('facets/requests', ['service' => 'billing']))->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/search')
        && str_contains(requestQuery($request)['q'] ?? '', 'resource.service.name = "cbox-web"'));
});
