<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Support\Period;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * A Prometheus holding exactly $names (schema detection) and two services.
 *
 * @param  list<string>  $names
 */
function fakeBootstrapBackends(array $names): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => $names]),
        'prometheus.test:9090/api/v1/label/service_name/values*' => Http::response(['status' => 'success', 'data' => ['shop', 'billing']]),
        'prometheus.test:9090/api/v1/label/deployment_environment_name/values*' => Http::response(['status' => 'success', 'data' => ['production']]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

/**
 * @param  list<array{group: string, pages: list<array{slug: string, label: string}>}>  $nav
 * @return array<string, list<string>>
 */
function navSlugs(array $nav): array
{
    $out = [];

    foreach ($nav as $group) {
        $out[$group['group']] = array_column($group['pages'], 'slug');
    }

    return $out;
}

it('groups the nav and keeps hidden detail pages out of it', function (): void {
    fakeBootstrapBackends(allMetricNames());

    $response = $this->getJson(apiUrl('bootstrap'))->assertOk();
    $nav = navSlugs($response->json('nav'));

    expect(array_keys($nav))->toContain('Overview', 'Activity', 'Queues', 'Frontend', 'Infrastructure', 'Statamic')
        ->and($nav['Overview'])->toContain('dashboard', 'traces')
        ->and($nav['Activity'])->toContain('requests', 'jobs', 'exceptions', 'queries')
        ->and($nav['Queues'])->toBe(['queues', 'autoscale', 'horizon'])
        ->and(array_merge(...array_values($nav)))->not->toContain('request-detail', 'job-detail', 'host-detail');

    // Hidden pages are still known (routable by drill-down), just flagged.
    expect($response->json('pages.request-detail'))->toBe(['label' => 'Request', 'group' => null, 'hidden' => true])
        ->and($response->json('pages.requests'))->toBe(['label' => 'Requests', 'group' => 'Activity', 'hidden' => false]);
});

it('filters the nav by schema detection', function (): void {
    // Only queue metrics exist: the queue page lights up, detect-gated others don't.
    fakeBootstrapBackends(['queue_metrics_pending_jobs']);

    $response = $this->getJson(apiUrl('bootstrap'))->assertOk();
    $nav = navSlugs($response->json('nav'));

    expect($nav['Queues'])->toBe(['queues'])
        ->and($nav)->not->toHaveKey('Statamic')
        ->and($nav['Activity'])->not->toContain('cache', 'livewire', 'commands')
        // Pages without a detect pattern are always there.
        ->and($nav['Activity'])->toContain('requests', 'jobs')
        ->and(array_keys($response->json('pages')))->not->toContain('horizon');
});

it('lists third-party pages in their group', function (): void {
    fakeBootstrapBackends([]);
    TelemetryUi::page('billing', 'Billing', group: 'Hubhus');

    expect(navSlugs($this->getJson(apiUrl('bootstrap'))->json('nav'))['Hubhus'])->toBe(['billing']);
});

it('ships the dimension registry, with declared dimensions', function (): void {
    fakeBootstrapBackends([]);
    TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus', link: 'https://crm.test/c/{value}', plural: 'Customers');

    $dimensions = collect($this->getJson(apiUrl('bootstrap'))->assertOk()->json('dimensions'))->keyBy('key');

    expect($dimensions['hubhus.customer_id'])->toBe([
        'key' => 'hubhus.customer_id',
        'label' => 'Customer',
        'group' => 'Hubhus',
        'entity' => 'hubhus.customer_id',
        'scope' => 'span',
        'builtin' => false,
        'signals' => ['requests', 'traces'],
        'format' => null,
        'plural' => 'Customers',
        'linksOut' => true,
        'resolvable' => false,
    ])->and($dimensions['http.route'])->toMatchArray(['entity' => 'route', 'builtin' => true, 'linksOut' => false])
        ->and($dimensions['host.name']['scope'])->toBe('resource')
        ->and($dimensions['status']['scope'])->toBe('intrinsic');
});

it('lists entity types: built-in aliases and every declared dimension', function (): void {
    fakeBootstrapBackends([]);
    TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus');

    $entities = collect($this->getJson(apiUrl('bootstrap'))->json('entities'))->keyBy('type');

    expect($entities->keys()->all())->toContain('route', 'user', 'ip', 'service', 'host', 'query', 'view', 'job', 'queue', 'outgoing', 'command', 'hubhus.customer_id')
        // Built-ins without an entity alias are dimensions, not entity pages.
        ->not->toContain('http.request.method', 'status');

    expect($entities['route'])->toBe(['type' => 'route', 'key' => 'http.route', 'label' => 'Route', 'plural' => 'Routes', 'custom' => false, 'group' => 'Request'])
        ->and($entities['query']['plural'])->toBe('Queries')
        ->and($entities['hubhus.customer_id'])->toMatchArray(['custom' => true, 'group' => 'Hubhus', 'plural' => 'Customers']);
});

it('ships the scope options, periods and explore signals', function (): void {
    fakeBootstrapBackends([]);

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('scope.services', ['billing', 'shop'])
        ->assertJsonPath('scope.environments', ['production'])
        ->assertJsonPath('scope.error', null)
        ->assertJsonPath('periods.0', ['value' => Period::cases()[0]->value, 'label' => Period::cases()[0]->label()])
        ->assertJsonCount(count(Period::cases()), 'periods')
        ->assertJsonPath('explore.*.signal', ['requests', 'traces', 'logs', 'errors'])
        ->assertJsonPath('app.version', '2.0')
        ->assertJsonPath('user', null);
});

it('still boots when scope discovery fails, and says why', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 503)]);

    $response = $this->getJson(apiUrl('bootstrap'))->assertOk();

    expect($response->json('scope.services'))->toBe([])
        ->and($response->json('nav'))->not->toBeEmpty(); // detection fails open
});

it('reports the viewer abilities and backend capabilities', function (): void {
    fakeBootstrapBackends([]);
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true);

    $this->getJson(apiUrl('bootstrap'))
        ->assertJsonPath('abilities.manage', true)
        ->assertJsonPath('abilities.createIssues', false) // no tracker
        ->assertJsonPath('capabilities.issues', false)
        ->assertJsonPath('capabilities.exactAggregation', false);

    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_x']);

    $this->getJson(apiUrl('bootstrap'))
        ->assertJsonPath('abilities.createIssues', true)
        ->assertJsonPath('capabilities.issues', true);
});

it('names the signed-in user', function (): void {
    fakeBootstrapBackends([]);

    $user = new User;
    $user->forceFill(['name' => 'Ada', 'email' => 'ada@example.test']);

    $this->actingAs($user)
        ->getJson(apiUrl('bootstrap'))
        ->assertJsonPath('user', ['name' => 'Ada', 'email' => 'ada@example.test']);
});
