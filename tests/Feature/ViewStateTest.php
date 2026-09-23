<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Support\ViewState;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => ['cbox-web', 'billing']]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
});

/**
 * The remembered-state cookie, as the browser would send it back.
 *
 * @return array<string, string>
 */
function rememberedView(string $state): array
{
    return [ViewState::DEFAULT_COOKIE => $state];
}

/**
 * The width, in seconds, of every range query the page actually issued —
 * what the reader is looking at, read off the wire rather than off a property.
 *
 * @return list<int>
 */
function queriedWindows(): array
{
    $windows = [];

    foreach (Http::recorded() as [$request]) {
        if (! str_contains($request->url(), 'query_range')) {
            continue;
        }

        $query = requestQuery($request);

        if (isset($query['start'], $query['end'])) {
            $windows[] = (int) $query['end'] - (int) $query['start'];
        }
    }

    return $windows;
}

/**
 * Every PromQL query the page sent, decoded.
 *
 * @return list<string>
 */
function sentPromQueries(): array
{
    $queries = [];

    foreach (Http::recorded() as [$request]) {
        if (! str_contains($request->url(), 'prometheus.test')) {
            continue;
        }

        $query = requestQuery($request)['query'] ?? null;

        if (is_string($query)) {
            $queries[] = rawurldecode($query);
        }
    }

    return $queries;
}

/** A JSON API call carrying the remembered-state cookie. */
function withRemembered(string $state): mixed
{
    return test()->withCredentials()->withUnencryptedCookies(rememberedView($state));
}

it('returns the remembered state in bootstrap', function (): void {
    withRemembered('period=7d&refresh=30&service=billing&env=')
        ->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('state.period', '7d')
        ->assertJsonPath('state.refresh', 30)
        ->assertJsonPath('state.service', 'billing')
        ->assertJsonPath('state.env', '')
        ->assertJsonPath('refreshIntervals', ViewState::INTERVALS);
});

it('lets an explicit url parameter beat the remembered range in bootstrap', function (): void {
    // A shared deep link must show the SENDER's view, never the recipient's.
    withRemembered('period=7d')
        ->getJson(apiUrl('bootstrap', ['period' => '15m']))
        ->assertOk()
        ->assertJsonPath('state.period', '15m');
});

it('reads a hand-edited refresh interval as off rather than obeying it', function (): void {
    withRemembered('refresh=1')->getJson(apiUrl('bootstrap'))->assertJsonPath('state.refresh', 0);
});

it('sets the cookie when the spa reports a move', function (): void {
    $response = $this->postJson(apiUrl('view-state'), ['period' => '7d', 'service' => 'billing', 'refresh' => 30])
        ->assertOk()
        ->assertJsonPath('state.period', '7d')
        ->assertJsonPath('state.service', 'billing')
        ->assertJsonPath('state.refresh', 30);

    $cookie = $response->getCookie(ViewState::DEFAULT_COOKIE, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->getValue())->toContain('period=7d')->toContain('service=billing')->toContain('refresh=30')
        ->and($cookie?->isHttpOnly())->toBeFalse();
});

it('validates what the spa reports like any url parameter', function (): void {
    $this->postJson(apiUrl('view-state'), ['period' => 'forever', 'refresh' => 1, 'from' => 'nonsense', 'to' => '1735689600'])
        ->assertOk()
        ->assertJsonPath('state.period', '1h')
        ->assertJsonPath('state.refresh', 0)
        ->assertJsonPath('state.from', '')
        ->assertJsonPath('state.to', '');
});

it('keeps a reported custom range', function (): void {
    $this->postJson(apiUrl('view-state'), ['from' => '1735686000', 'to' => '1735689600'])
        ->assertOk()
        ->assertJsonPath('state.from', '1735686000')
        ->assertJsonPath('state.to', '1735689600');
});

it('never remembers a scope outside the tenancy lock', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['cbox-web']]);

    $this->postJson(apiUrl('view-state'), ['service' => 'billing'])->assertOk()->assertJsonPath('state.service', '');
    $this->postJson(apiUrl('view-state'), ['service' => 'cbox-web'])->assertOk()->assertJsonPath('state.service', 'cbox-web');
});

it('sets no cookie when persistence is off, and ignores a remembered one', function (): void {
    config()->set('telemetry-ui.state.enabled', false);

    $response = $this->postJson(apiUrl('view-state'), ['period' => '7d'])->assertOk();
    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();

    withRemembered('period=7d')->getJson(apiUrl('bootstrap'))->assertJsonPath('state.period', '1h');
});

it('scopes panel queries to the remembered range when the url is silent', function (): void {
    withRemembered('period=7d')->getJson(panelUrl('requests-activity'))->assertOk();

    expect(queriedWindows())->not->toBeEmpty()
        ->and(queriedWindows())->each->toBe(7 * 86400);
});

it('lets an explicit url range beat the remembered one in panel queries', function (): void {
    withRemembered('period=7d')->getJson(panelUrl('requests-activity', ['period' => '15m']))->assertOk();

    expect(queriedWindows())->not->toBeEmpty()->each->toBe(900);
});

it('lets a preset clear a remembered custom range', function (): void {
    // The range is one unit: a URL ?period= must not pick up a remembered from/to.
    withRemembered('period=1h&from=1735686000&to=1735689600')
        ->getJson(panelUrl('requests-activity', ['period' => '24h']))
        ->assertOk();

    expect(queriedWindows())->not->toBeEmpty()->each->toBe(86400);
});

it('keeps a remembered custom range across a bare request', function (): void {
    withRemembered('period=1h&from=1735686000&to=1735689600')->getJson(panelUrl('requests-activity'))->assertOk();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'query_range')) {
            return false;
        }

        $query = requestQuery($request);

        return ($query['start'] ?? null) === '1735686000' && ($query['end'] ?? null) === '1735689600';
    });
});

it('scopes panel queries to the remembered service when the url is silent', function (): void {
    withRemembered('service=billing')->getJson(panelUrl('requests-activity'))->assertOk();

    expect(array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name="billing"')))->not->toBeEmpty();
});

it('lets an explicitly empty scope parameter clear a remembered one', function (): void {
    // "All services" SETS ?service= to empty: an absent parameter is
    // indistinguishable from "not specified" and the remembered scope would return.
    withRemembered('service=billing')->getJson(panelUrl('requests-activity', ['service' => '']))->assertOk();

    expect(sentPromQueries())->not->toBeEmpty();
    expect(array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name=')))->toBeEmpty();
});

it('never lets a remembered scope reach outside a tenancy lock', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['cbox-web']]);

    withRemembered('service=billing')->getJson(panelUrl('requests-activity'))->assertOk();

    $scoped = array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name'));

    expect($scoped)->not->toBeEmpty();

    foreach ($scoped as $query) {
        expect($query)->toContain('service_name="cbox-web"')->not->toContain('billing');
    }
});

it('does not persist state on an api read, which the panels drive', function (): void {
    Event::fake([ViewStateChanged::class]);

    $response = $this->getJson(panelUrl('requests-activity', ['period' => '7d']))->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
    Event::assertNotDispatched(ViewStateChanged::class);
});

it('remembers a range taken from the url of a page load', function (): void {
    $response = $this->get('/telemetry-ui/p/requests?period=7d')->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false)?->getValue())->toContain('period=7d');
});

it('sets no cookie for a reader who never chose anything', function (): void {
    $response = $this->get('/telemetry-ui/p/requests')->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

it('tells a host when a page load moves the view, and stays quiet otherwise', function (): void {
    Event::fake([ViewStateChanged::class]);

    $this->get('/telemetry-ui/p/requests?period=7d')->assertOk();
    Event::assertDispatched(ViewStateChanged::class, fn (ViewStateChanged $event): bool => $event->state->period()->value === '7d');

    Event::fake([ViewStateChanged::class]);

    $this->withUnencryptedCookies(rememberedView('period=7d'))->get('/telemetry-ui/p/requests')->assertOk();
    Event::assertNotDispatched(ViewStateChanged::class);
});

it('lets a host read and move the view state', function (): void {
    $state = app(ViewState::class);

    $state->put(['period' => '30d']);

    expect($state->period()->value)->toBe('30d')
        ->and($state->changed())->toBeTrue();

    [$start, $end] = $state->range();
    expect($end->getTimestamp() - $start->getTimestamp())->toBe(30 * 86400);

    $state->forget();
    expect($state->period()->value)->toBe('1h');
});

it('pins the whole view into its query params, not just what is in the address bar', function (): void {
    $state = app(ViewState::class);
    $state->put(['period' => '7d', 'service' => 'cbox-web']);

    // Empty but PRESENT: "?env=" is an explicit "all environments".
    expect($state->queryParams())->toBe(['period' => '7d', 'service' => 'cbox-web', 'env' => '']);

    $state->put(['from' => '1735686000', 'to' => '1735689600']);
    expect($state->queryParams())->toMatchArray(['from' => '1735686000', 'to' => '1735689600']);
});

it('never writes a cookie on the asset route, which is cached far into the future', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/build/assets/nope.js?period=15m');

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

it('can be told not to offer a link that cannot travel', function (): void {
    // A desktop host serves the dashboard from 127.0.0.1 on a port it picked.
    config()->set('telemetry-ui.copy_link', false);

    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('app.copyLink', false);
});

it('offers the copy link by default, because most hosts are reachable', function (): void {
    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('app.copyLink', true);
});
