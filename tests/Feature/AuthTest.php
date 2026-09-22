<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Cbox\TelemetryUi\Tests\Fixtures\DummyPanel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Fleet discovery + schema detection touch Prometheus; keep them quiet so
    // endpoints answer without a real backend.
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
});

/** Deny only the given pages; the master check (page = null) stays open. */
function denyPages(string ...$pages): void
{
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => ! in_array($page, $pages, true));
}

it('answers the api with a typed 403 when the master gate is closed', function (string $path): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => false);

    $this->getJson(apiUrl($path))
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden');

    // Not only for clients that ask for JSON: an API URL never serves HTML.
    $this->get(apiUrl($path))->assertForbidden()->assertJsonPath('error.type', 'forbidden');
})->with(['bootstrap', 'pages/dashboard', 'panels/requests-activity', 'explore/requests', 'facets/logs', 'entities/route', 'traces/abc123', 'annotations', 'stream/logs']);

it('403s the spa shell when the master gate is closed', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => false);

    $this->get('/telemetry-ui')->assertForbidden();
    $this->get('/telemetry-ui/explore/logs')->assertForbidden();
});

it('lets the built assets through a closed gate', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => false);

    // Missing file → 404, not 403: the gate never ran.
    $this->get('/telemetry-ui/build/assets/nope.js')->assertNotFound();
});

it('403s a page the per-page gate denies, and every panel on it', function (): void {
    denyPages('logs');

    $this->getJson(apiUrl('pages/logs'))->assertForbidden()->assertJsonPath('error.type', 'forbidden');
    $this->getJson(panelUrl('log-viewer'))->assertForbidden()->assertJsonPath('error.type', 'forbidden');

    // Other pages are untouched.
    $this->getJson(apiUrl('pages/requests'))->assertOk();
    $this->getJson(panelUrl('routes-table'))->assertOk();
});

it('serves a panel shared by an allowed and a denied page', function (): void {
    app(TelemetryUiManager::class)->page('secret', 'Secret')->panel(DummyPanel::class, 'secret')->panel(DummyPanel::class, 'requests');
    denyPages('secret');

    // Reachable through the page the viewer may open.
    $this->getJson(panelUrl('dummy-panel'))->assertOk();

    denyPages('secret', 'requests');

    $this->getJson(panelUrl('dummy-panel'))->assertForbidden();
});

it('gates each explore signal and its facets on the page that owns it', function (string $signal, string $page): void {
    denyPages($page);

    $this->getJson(apiUrl('explore/'.$signal))->assertForbidden()->assertJsonPath('error.type', 'forbidden');
    $this->getJson(apiUrl('facets/'.$signal))->assertForbidden()->assertJsonPath('error.type', 'forbidden');
})->with([
    ['requests', 'requests'],
    ['traces', 'traces'],
    ['logs', 'logs'],
    ['errors', 'exceptions'],
]);

it('gates the trace, error-group and live-tail endpoints on their pages', function (): void {
    denyPages('traces', 'exceptions', 'logs', 'requests');

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))->assertForbidden();
    $this->getJson(apiUrl('errors/abc123def456'))->assertForbidden();
    $this->getJson(apiUrl('stream/logs', ['once' => 1]))->assertForbidden();
    $this->getJson(apiUrl('stream/requests', ['once' => 1]))->assertForbidden();
});

it('omits denied pages and explore signals from the bootstrap nav', function (): void {
    denyPages('logs', 'exceptions');

    $response = $this->getJson(apiUrl('bootstrap'))->assertOk();

    $slugs = collect($response->json('nav'))->flatMap(fn (array $group): array => array_column($group['pages'], 'slug'))->all();

    expect($slugs)->toContain('requests')->not->toContain('logs')->not->toContain('exceptions')
        ->and(array_keys($response->json('pages')))->not->toContain('logs')
        ->and(array_column($response->json('explore'), 'signal'))->toBe(['requests', 'traces']);
});

it('reports the manage ability in bootstrap', function (): void {
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);
    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('abilities.manage', false)->assertJsonPath('abilities.createIssues', false);

    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true);
    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('abilities.manage', true);
});

it('blocks issue creation without the manage ability', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github', 'repo' => 'cboxdk/laravel-telemetry-ui', 'token' => 'ghp_x',
    ]);
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);

    $this->postJson(apiUrl('issues'), ['title' => 'Boom', 'body' => 'x', 'labels' => []])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden')
        ->assertJsonPath('error.message', 'You are not authorized to create issues.');

    // The write never reached the tracker.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.github.com'));
});
