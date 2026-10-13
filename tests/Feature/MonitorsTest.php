<?php

declare(strict_types=1);

use Cbox\Telemetry\Directives\Directive;
use Cbox\Telemetry\Directives\DirectiveStore;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\ListMonitorsTool;
use Cbox\TelemetryUi\Mcp\Tools\MonitorTool;
use Cbox\TelemetryUi\Monitors\SignedHttpDirectives;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/*
 * Monitors: cboxdk/laravel-telemetry directives placed from the dashboard,
 * in-process when this app runs the core, over the signed endpoint when the
 * service is elsewhere — always within the viewer's scope.
 */

const SHOP_SECRET = 'shop-directives-secret';

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true);

    // No local core by default: the HTTP transport is the one most tests use.
    config()->set('telemetry-ui.monitors.local', false);
    config()->set('telemetry-ui.monitors.services', [
        'shop' => ['url' => 'https://shop.test', 'secret' => SHOP_SECRET, 'environment' => 'production'],
    ]);
});

/** The core's in-process store, on an array cache, bound as this app's TelemetryManager. */
function localCore(string $service = 'billing'): DirectiveStore
{
    config()->set('telemetry-ui.monitors.local', true);
    config()->set('telemetry.service.name', $service);
    config()->set('telemetry.service.environment', 'production');

    $store = new DirectiveStore(static fn () => app('cache')->store('array'));

    app()->instance('Cbox\\Telemetry\\TelemetryManager', new class($store)
    {
        public function __construct(private readonly DirectiveStore $store) {}

        public function directives(): DirectiveStore
        {
            return $this->store;
        }
    });

    return $store;
}

/** Was this request signed the way the core verifies it: timestamp, method, directive id and body? */
function signedCorrectly(Request $request, string $id = ''): bool
{
    $timestamp = $request->header('X-Telemetry-Timestamp')[0] ?? '';

    return ctype_digit($timestamp)
        && abs(time() - (int) $timestamp) <= 300
        && hash_equals(SignedHttpDirectives::sign(SHOP_SECRET, $timestamp, $request->method(), $id, $request->body()), $request->header('X-Telemetry-Signature')[0] ?? '');
}

function shopDirective(array $overrides = []): array
{
    return [
        'id' => '01JD0000000000000000000000',
        'match' => 'route',
        'key' => null,
        'value' => 'checkout.*',
        'profile' => true,
        'expires_at' => time() + 900,
        'reason' => 'ticket 881',
        'created_by' => 'Ada <ada@example.com>',
        ...$overrides,
    ];
}

it('places a monitor on a remote service with a signed request, as the dashboard user', function (): void {
    Http::fake([
        'shop.test/telemetry/directives' => Http::response(['directive' => shopDirective()], 201),
    ]);

    $user = new User;
    $user->forceFill(['name' => 'Ada', 'email' => 'ada@example.com']);

    $this->actingAs($user)->postJson(apiUrl('monitors'), [
        'service' => 'shop',
        'match' => 'route',
        'value' => 'checkout.*',
        'minutes' => 15,
        'profile' => true,
        'reason' => 'ticket 881',
    ])->assertCreated()
        ->assertJsonPath('monitor.id', '01JD0000000000000000000000')
        ->assertJsonPath('monitor.service', 'shop')
        ->assertJsonPath('monitor.describe', 'route checkout.*');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === 'https://shop.test/telemetry/directives'
            && signedCorrectly($request)
            && $body === ['match' => 'route', 'value' => 'checkout.*', 'minutes' => 15, 'profile' => true, 'reason' => 'ticket 881', 'created_by' => 'Ada <ada@example.com>'];
    });
});

it('lists, counts down and removes monitors, signing the empty body of a GET and a DELETE', function (): void {
    Http::fake([
        'shop.test/telemetry/directives/*' => Http::response(null, 204),
        'shop.test/telemetry/directives' => Http::response(['directives' => [
            shopDirective(),
            shopDirective(['id' => 'old', 'expires_at' => time() - 5]),
        ]]),
    ]);

    $this->getJson(apiUrl('monitors'))
        ->assertOk()
        ->assertJsonPath('services.0', ['service' => 'shop', 'transport' => 'http', 'environment' => 'production'])
        // An expired directive the store has not swept yet is not "active".
        ->assertJsonCount(1, 'monitors')
        ->assertJsonPath('monitors.0.reason', 'ticket 881')
        ->assertJsonPath('monitors.0.createdBy', 'Ada <ada@example.com>')
        ->assertJsonPath('canManage', true);

    $this->deleteJson(apiUrl('monitors/shop/01JD0000000000000000000000'))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://shop.test/telemetry/directives/01JD0000000000000000000000'
        && $request->body() === ''
        && signedCorrectly($request, '01JD0000000000000000000000'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && signedCorrectly($request));
});

it('says which service could not be read, and still lists the others', function (): void {
    localCore('billing')->add(Directive::user('42')->for(30)->because('local one'));
    Http::fake(['shop.test/*' => Http::response(['error' => 'Invalid signature.'], 401)]);

    $this->getJson(apiUrl('monitors'))
        ->assertOk()
        ->assertJsonCount(1, 'monitors')
        ->assertJsonPath('monitors.0.service', 'billing')
        ->assertJsonPath('errors.shop', fn (string $m): bool => str_contains($m, 'refused the signature'));
})->skip(! class_exists(DirectiveStore::class), 'Needs cboxdk/laravel-telemetry 3.5+ (directives) installed.');

it('uses the core in-process when the monitored service is this app', function (): void {
    $store = localCore('billing');
    config()->set('telemetry-ui.monitors.services', []);
    Http::fake();

    $this->postJson(apiUrl('monitors'), [
        'service' => 'billing', 'match' => 'context', 'key' => 'tenant.id', 'value' => '7', 'minutes' => 60,
    ])->assertCreated()->assertJsonPath('monitor.describe', 'tenant.id = 7');

    expect($store->all())->toHaveCount(1)
        ->and($store->all()[0]->key)->toBe('tenant.id')
        ->and($store->all()[0]->createdBy)->toBe('telemetry-ui');

    $this->getJson(apiUrl('monitors'))->assertJsonPath('services.0.transport', 'local');
    $this->deleteJson(apiUrl('monitors/billing/'.$store->all()[0]->id))->assertOk();
    expect($store->all())->toBe([]);

    Http::assertNothingSent();
})->skip(! class_exists(DirectiveStore::class), 'Needs cboxdk/laravel-telemetry 3.5+ (directives) installed.');

it('needs the manage ability to place or remove a monitor, and the view ability to list them', function (): void {
    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);
    Http::fake();

    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'user', 'value' => '42', 'minutes' => 15])->assertForbidden();
    $this->deleteJson(apiUrl('monitors/shop/abc'))->assertForbidden();

    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => $page !== 'monitors');
    $this->getJson(apiUrl('monitors'))->assertForbidden();

    Http::assertNothingSent();
});

it('refuses what the core would refuse before calling it', function (array $body, string $message): void {
    Http::fake();

    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'minutes' => 15, ...$body])
        ->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, $message));

    Http::assertNothingSent();
})->with([
    'unknown match' => [['match' => 'everything', 'value' => 'x'], 'matches one of'],
    'context without a key' => [['match' => 'context', 'value' => '7'], 'needs the dimension'],
    'a route that matches everything' => [['match' => 'route', 'value' => '*'], 'matches everything'],
    'too long' => [['match' => 'user', 'value' => '42', 'minutes' => 10_000], '1 to 1440'],
]);

it('only offers services inside the viewer\'s scope lock', function (): void {
    config()->set('telemetry-ui.monitors.services', [
        'shop' => ['url' => 'https://shop.test', 'secret' => SHOP_SECRET, 'environment' => 'production'],
        'admin' => ['url' => 'https://admin.test', 'secret' => 'x'],
    ]);
    TelemetryUi::restrictScopeUsing(fn (?object $user): array => ['services' => ['shop'], 'environments' => ['production']]);
    Http::fake(['shop.test/*' => Http::response(['directives' => []])]);

    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('monitors.services', ['shop']);
    $this->postJson(apiUrl('monitors'), ['service' => 'admin', 'match' => 'user', 'value' => '1', 'minutes' => 15])
        ->assertForbidden();

    // An environment lock hides a target whose environment is unknown.
    TelemetryUi::restrictScopeUsing(fn (?object $user): array => ['environments' => ['production']]);
    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('monitors.services', ['shop']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.test'));
});

it('confines a tenant-locked viewer to monitors on its own tenant', function (): void {
    TelemetryUi::restrictScopeUsing(fn (?object $user): array => ['contexts' => ['tenant.id' => [7]]]);
    Http::fake([
        'shop.test/telemetry/directives/*' => Http::response(null, 204),
        'shop.test/telemetry/directives' => function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['directive' => shopDirective(['id' => 'mine', 'match' => 'context', 'key' => 'tenant.id', 'value' => '7'])], 201)
                : Http::response(['directives' => [
                    shopDirective(['id' => 'mine', 'match' => 'context', 'key' => 'tenant.id', 'value' => '7']),
                    shopDirective(['id' => 'theirs', 'match' => 'context', 'key' => 'tenant.id', 'value' => '8']),
                    shopDirective(['id' => 'global']),
                ]]);
        },
    ]);

    // A route, path, job or user directive matches across every tenant.
    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*', 'minutes' => 15])
        ->assertForbidden()
        ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, "every tenant's traffic"));
    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'context', 'key' => 'tenant.id', 'value' => '8', 'minutes' => 15])
        ->assertForbidden();
    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'context', 'key' => 'tenant.id', 'value' => '7', 'minutes' => 15])
        ->assertCreated();

    // It sees, and can remove, only its own.
    $this->getJson(apiUrl('monitors'))->assertJsonCount(1, 'monitors')->assertJsonPath('monitors.0.id', 'mine');
    $this->deleteJson(apiUrl('monitors/shop/theirs'))->assertNotFound();
    $this->deleteJson(apiUrl('monitors/shop/global'))->assertNotFound();
    $this->deleteJson(apiUrl('monitors/shop/mine'))->assertOk();

    Http::assertSentCount(6);
});

it('offers nothing when no service is monitorable', function (): void {
    config()->set('telemetry-ui.monitors.services', []);

    $this->getJson(apiUrl('bootstrap'))->assertJsonPath('monitors.services', []);
    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'user', 'value' => '1', 'minutes' => 15])->assertForbidden();
});

it('lists monitors over MCP', function (): void {
    Http::fake(['shop.test/*' => Http::response(['directives' => [shopDirective()]])]);

    TelemetryServer::tool(ListMonitorsTool::class)
        ->assertOk()
        ->assertSee('route checkout.*')
        ->assertSee('ticket 881');
});

it('keeps the MCP monitor tool off until write tools are switched on', function (): void {
    Http::fake(['shop.test/*' => Http::response(['directive' => shopDirective()], 201)]);

    config()->set('telemetry-ui.mcp.write', false);
    TelemetryServer::tool(MonitorTool::class, ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*'])->assertHasErrors();
    Http::assertNothingSent();

    config()->set('telemetry-ui.mcp.write', true);
    TelemetryServer::tool(MonitorTool::class, ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*', 'minutes' => 15, 'reason' => 'agent'])
        ->assertOk()
        ->assertSee('01JD0000000000000000000000')
        ->assertSee('telemetry.directive');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['created_by'] === 'mcp' && signedCorrectly($request));
});

it('filters requests to those a monitor recorded', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    $this->getJson(apiUrl('explore/requests', ['where' => ['telemetry.directive=~.+']]))->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'telemetry.directive =~ ".+"'));
});

it('allows no monitor at all under a context lock on two dimensions', function (): void {
    // A directive matches one dimension: tenant.id = 7 would also record
    // team 4 of tenant 7, outside a viewer locked to team 3.
    TelemetryUi::restrictScopeUsing(fn (?object $user): array => ['contexts' => ['tenant.id' => ['7'], 'team.id' => ['3']]]);
    Http::fake(['shop.test/*' => Http::response(['directives' => [shopDirective(['id' => 'x', 'match' => 'context', 'key' => 'tenant.id', 'value' => '7'])]])]);

    foreach ([['key' => 'tenant.id', 'value' => '7'], ['key' => 'team.id', 'value' => '3']] as $context) {
        $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'context', ...$context, 'minutes' => 15])
            ->assertForbidden()
            ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'one dimension'));
    }

    $this->getJson(apiUrl('monitors'))->assertJsonCount(0, 'monitors');
});

it('fails closed on a malformed context lock', function (mixed $contexts): void {
    TelemetryUi::restrictScopeUsing(fn (?object $user): array => ['contexts' => $contexts]);
    Http::fake(['shop.test/*' => Http::response(['directives' => [shopDirective()]])]);

    expect(app(ScopeLock::class)->contexts())->toBe([]);

    $this->postJson(apiUrl('monitors'), ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*', 'minutes' => 15])->assertForbidden();
    $this->getJson(apiUrl('monitors'))->assertJsonCount(0, 'monitors');
})->with([
    'a string' => ['tenant.id=7'],
    'a number' => [7],
    'true' => [true],
]);

it('asks an HTTP MCP agent for the manage ability before it places a monitor', function (): void {
    config()->set('telemetry-ui.mcp.write', true);
    Http::fake(['shop.test/*' => Http::response(['directive' => shopDirective()], 201)]);
    // The HTTP transport: an agent acting as a dashboard user, not stdio.
    (fn () => $this->isRunningInConsole = false)->call(app());

    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => false);
    TelemetryServer::tool(MonitorTool::class, ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*'])
        ->assertHasErrors(['manageTelemetryUi']);
    Http::assertNothingSent();

    Gate::define('manageTelemetryUi', fn (?object $user = null): bool => true);
    TelemetryServer::tool(MonitorTool::class, ['service' => 'shop', 'match' => 'route', 'value' => 'checkout.*', 'minutes' => 15])->assertOk();
});

it('signs exactly as the core verifies, method and directive id included', function (): void {
    $core = 'Cbox\\Telemetry\\Http\\Controllers\\DirectivesController';

    foreach ([['GET', '', ''], ['POST', '', '{"match":"user","value":"42","minutes":15}'], ['DELETE', '01JD0000000000000000000000', '']] as [$method, $id, $body]) {
        expect(SignedHttpDirectives::sign('s3cret', '1760000000', $method, $id, $body))
            ->toBe($core::signature('s3cret', '1760000000', $method, $id, $body));
    }

    // A list call's signature is not a delete's, for any id.
    expect(SignedHttpDirectives::sign('s3cret', '1760000000', 'GET', '', ''))
        ->not->toBe(SignedHttpDirectives::sign('s3cret', '1760000000', 'DELETE', '', ''));
})->skip(! method_exists('Cbox\\Telemetry\\Http\\Controllers\\DirectivesController', 'signature'), 'Needs cboxdk/laravel-telemetry 3.5+ (DirectivesController::signature).');

it('never sends one signature twice, and re-signs a call the app has seen', function (): void {
    $signatures = [];
    $refused = false;

    Http::fake(['shop.test/*' => function (Request $request) use (&$signatures, &$refused) {
        $signatures[] = $request->header('X-Telemetry-Signature')[0];

        // Another process signed the same call in the same second once.
        if (! $refused) {
            $refused = true;

            return Http::response(['error' => 'Signature already used.'], 401);
        }

        return Http::response(['directives' => []]);
    }]);

    // Two list reads in the same second, the first one refused once.
    $this->getJson(apiUrl('monitors'))->assertOk()->assertJsonPath('errors', []);
    $this->getJson(apiUrl('monitors'))->assertOk()->assertJsonPath('errors', []);

    expect($signatures)->toHaveCount(3)
        ->and(array_unique($signatures))->toHaveCount(3);
});
