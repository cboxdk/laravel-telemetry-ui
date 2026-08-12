<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Support\ViewState;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

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

it('keeps the picked range on a reload of a bare url', function (): void {
    // The reader picks 7 days...
    $picked = $this->get('/telemetry-ui/requests?period=7d')->assertOk();

    $cookie = $picked->getCookie(ViewState::DEFAULT_COOKIE, false);
    expect($cookie)->not->toBeNull()
        ->and($cookie?->getValue())->toContain('period=7d');

    // ...then lands on the same page with nothing in the URL at all.
    $reloaded = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    // The header says 7 days...
    $reloaded->assertSee('class="tui-period is-active"', false);
    expect(activePreset($reloaded->getContent()))->toBe('7D');

    // ...and so do the queries behind it, which is the part that matters:
    // the cards had the right window before they ever hit the backend.
    expect(queriedWindows())->not->toBeEmpty()
        ->and(queriedWindows())->each->toBe(7 * 86400);
});

it('carries the range through a link that says nothing about it', function (): void {
    // A host's navLink, a card drill-in, a pasted bare URL — none of them
    // carry the dashboard's query string, and all of them used to reset it.
    $response = $this->withUnencryptedCookies(rememberedView('period=24h'))
        ->get('/telemetry-ui/jobs')
        ->assertOk();

    expect(activePreset($response->getContent()))->toBe('24H')
        ->and(queriedWindows())->each->toBe(86400);
});

it('lets an explicit url parameter beat the remembered range', function (): void {
    // A shared deep link must show the SENDER's view, never the recipient's.
    $response = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/requests?period=15m')
        ->assertOk();

    expect(activePreset($response->getContent()))->toBe('15M')
        ->and(queriedWindows())->each->toBe(900);
});

it('remembers a range taken from the url', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/requests?period=15m')
        ->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false)?->getValue())->toContain('period=15m');
});

it('lets a preset clear a remembered custom range', function (): void {
    // The preset buttons send ?period= and drop from/to. If from/to fell back
    // to the cookie key-by-key, clicking a preset would appear to do nothing.
    $response = $this->withUnencryptedCookies(rememberedView('period=1h&from=1735686000&to=1735689600'))
        ->get('/telemetry-ui/requests?period=24h')
        ->assertOk();

    $response->assertSee('Custom')->assertDontSee('01/01 07:00');

    expect(activePreset($response->getContent()))->toBe('24H')
        ->and(queriedWindows())->each->toBe(86400);
});

it('keeps a remembered custom range across a bare navigation', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('period=1h&from=1735686000&to=1735689600'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    // The header shows the absolute window, not "Custom"...
    $response->assertDontSee('>Custom<', false);

    // ...and the queries use exactly it.
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'query_range')) {
            return false;
        }

        $query = requestQuery($request);

        return ($query['start'] ?? null) === '1735686000' && ($query['end'] ?? null) === '1735689600';
    });
});

it('ignores a remembered range entirely when persistence is off', function (): void {
    config()->set('telemetry-ui.state.enabled', false);

    $response = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    expect(activePreset($response->getContent()))->toBe('1H')
        ->and($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

it('sets no cookie for a reader who never chose anything', function (): void {
    $response = $this->get('/telemetry-ui/requests')->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

it('shows the auto-refresh interval that is actually running', function (): void {
    // The control used to restore its timer from sessionStorage while its
    // label came from the server-rendered <option>, which was always "off".
    // Both now come from one place, so they cannot disagree.
    $response = $this->withUnencryptedCookies(rememberedView('refresh=30'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    $response->assertSee('telemetryUiRefresh(30,', false)
        ->assertSee('<option value="30" selected>⟳ 30s</option>', false);
});

it('offers the refresh control off by default', function (): void {
    $this->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertSee('telemetryUiRefresh(0,', false)
        ->assertSee('<option value="0" selected>⟳ off</option>', false);
});

it('reads a hand-edited refresh interval as off rather than obeying it', function (): void {
    $this->withUnencryptedCookies(rememberedView('refresh=1'))
        ->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertSee('telemetryUiRefresh(0,', false);
});

it('pins the whole view into the copy link, not just what is in the address bar', function (): void {
    // Otherwise a copied bare URL retargets to whatever range the RECIPIENT
    // last used — a worse bug than the one the cookie fixes.
    $response = $this->withUnencryptedCookies(rememberedView('period=7d&service=cbox-web'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    expect(copyLinkParams($response->getContent()))->toBe([
        'period' => '7d',
        // Empty but PRESENT: "?env=" is an explicit "all environments", which
        // is exactly what has to survive the trip to the recipient.
        'service' => 'cbox-web',
        'env' => '',
    ]);
});

it('pins a custom range into the copy link too', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('from=1735686000&to=1735689600'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    expect(copyLinkParams($response->getContent()))
        ->toMatchArray(['from' => '1735686000', 'to' => '1735689600']);
});

it('refreshes now by re-running the cards instead of throwing the page away', function (): void {
    $this->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertSee("window.Livewire.dispatch('telemetry-ui:refresh')", false);
});

it('remembers the scope and shows it selected in the picker', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('service=billing'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    $response->assertSee('<option value="billing" selected>billing</option>', false);

    expect(sentPromQueries())->not->toBeEmpty();
    expect(array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name="billing"')))->not->toBeEmpty();
});

it('lets an explicitly empty scope parameter clear a remembered one', function (): void {
    // "All services" deletes nothing — it SETS the parameter to empty, because
    // an absent parameter is indistinguishable from "not specified" and the
    // remembered scope would come straight back.
    $response = $this->withUnencryptedCookies(rememberedView('service=billing'))
        ->get('/telemetry-ui/requests?service=')
        ->assertOk();

    $response->assertSee('<option value="" selected>All services</option>', false);

    expect(array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name=')))->toBeEmpty();
});

it('never lets a remembered scope reach outside a tenancy lock', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['cbox-web']]);

    $this->withUnencryptedCookies(rememberedView('service=billing'))
        ->get('/telemetry-ui/requests')
        ->assertOk();

    $scoped = array_filter(sentPromQueries(), fn (string $q): bool => str_contains($q, 'service_name'));

    expect($scoped)->not->toBeEmpty();

    foreach ($scoped as $query) {
        expect($query)->toContain('service_name="cbox-web"')
            ->and($query)->not->toContain('billing');
    }
});

it('drops a locked-out remembered scope from the picker too, so it cannot lie', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['cbox-web']]);

    $this->withUnencryptedCookies(rememberedView('service=billing'))
        ->get('/telemetry-ui/requests')
        ->assertOk()
        ->assertDontSee('value="billing" selected', false);
});

it('reads the page url off the referer on a livewire request', function (): void {
    // Lazy cards mount in their own request, where the URL is Livewire's
    // endpoint — the same place Livewire's own #[Url] looks.
    $state = app(ViewState::class);

    $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->withHeaders(['X-Livewire' => 'true', 'Referer' => 'http://localhost/telemetry-ui/requests?period=15m'])
        ->get('/telemetry-ui/requests')
        ->assertOk();

    expect($state->period()->value)->toBe('15m');
});

it('does not persist state on a livewire request', function (): void {
    $response = $this->withHeaders(['X-Livewire' => 'true', 'Referer' => 'http://localhost/telemetry-ui/requests?period=7d'])
        ->get('/telemetry-ui/requests')
        ->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

it('tells a host when the reader moves the view, and stays quiet otherwise', function (): void {
    Event::fake([ViewStateChanged::class]);

    $this->get('/telemetry-ui/requests?period=7d')->assertOk();
    Event::assertDispatched(ViewStateChanged::class, fn (ViewStateChanged $event): bool => $event->state->period()->value === '7d');

    Event::fake([ViewStateChanged::class]);

    $this->withUnencryptedCookies(rememberedView('period=7d'))->get('/telemetry-ui/requests')->assertOk();
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

it('never writes a cookie on the asset route, which is cached far into the future', function (): void {
    $response = $this->withUnencryptedCookies(rememberedView('period=7d'))
        ->get('/telemetry-ui/assets/telemetry-ui.css')
        ->assertOk();

    expect($response->getCookie(ViewState::DEFAULT_COOKIE, false))->toBeNull();
});

/**
 * The label of the period preset the page rendered as active — the answer the
 * reader actually gets when they look at the header.
 */
/**
 * The state the "Copy link" button will pin into the URL, read back off the
 * rendered page. Blade's @js escapes every quote as a \\u0022 sequence so the
 * JSON can live inside an HTML attribute; undo that to read it.
 *
 * @return array<string, string>
 */
function copyLinkParams(string $html): array
{
    preg_match("/telemetryUiCopyLink\(JSON\.parse\('(.*?)'\)\)/", $html, $matches);

    $decoded = json_decode(str_replace('\\u0022', '"', $matches[1] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

function activePreset(string $html): string
{
    preg_match('/class="tui-period is-active"[^>]*>([^<]+)<\/button>/', $html, $matches);

    return trim($matches[1] ?? '');
}

/**
 * A desktop host serves the dashboard from 127.0.0.1 on a port it picked. The
 * link that button copies only ever resolves on that machine, while that app
 * is running — a button offering to share something unshareable.
 */
it('can be told not to offer a link that cannot travel', function (): void {
    config()->set('telemetry-ui.copy_link', false);

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertDontSee('tui-copy-link', false)
        ->assertDontSee('Copy link');
});

it('offers it by default, because most hosts are reachable', function (): void {
    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('tui-copy-link', false);
});
