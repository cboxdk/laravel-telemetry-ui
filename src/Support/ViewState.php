<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Events\ViewStateChanged;
use Cbox\TelemetryUi\Http\Middleware\RemembersViewState;
use Cbox\TelemetryUi\TelemetryUiManager;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The reader's view state — the time window, the auto-refresh interval and the
 * service/environment scope — resolved once per request and remembered between
 * them.
 *
 * ## Why this exists
 *
 * These three things used to live only in the query string, which meant they
 * survived exactly the links that bothered to carry them. A host's
 * {@see TelemetryUiManager::navLink()}, a card's deep link, a trace drill-in or
 * a plain reload of a bare URL all dropped back to the default, and the reader
 * who picked "last 7 days" quietly ended up looking at the last hour.
 *
 * ## Why a cookie
 *
 * The cards are server-rendered Livewire components: they query the backend
 * during the first render, so the window has to be known in PHP *before* the
 * query runs. A client-side store (localStorage, Alpine) is too late — the page
 * would paint the default range, run a full round of backend queries against
 * the wrong window, and only then jump. A cookie is on the request, so the very
 * first render is already correct, and it costs no storage, no session and no
 * authenticated user — which matters for hosts that have none.
 *
 * The cookie is a *preference*, never an authorization input. Nothing here
 * grants access to anything: a remembered scope is bounded by the tenancy
 * {@see ScopeLock} on the way in (see {@see withinLock()}) and every query is
 * independently forced into that lock downstream, exactly as it is for a
 * hand-typed `?service=`.
 *
 * ## Precedence
 *
 * An explicit URL parameter always wins, and taking one updates what is
 * remembered ({@see RemembersViewState}). So a shared deep link shows the
 * sender's view, not the recipient's saved one — and a bare link shows the
 * recipient's saved one, not the default.
 *
 * The range is resolved as ONE unit: `period`, `from` and `to` only mean
 * anything together. A preset button sends `?period=…` and deliberately drops
 * `from`/`to`, so falling back to a remembered `from`/`to` key-by-key would
 * make clicking a preset appear to do nothing.
 *
 * @api Read it with `TelemetryUi::viewState()`, change it with {@see put()},
 *      and listen for {@see ViewStateChanged} to be told when the reader moves
 *      it. See docs/extension-points/view-state.md.
 *
 * @phpstan-type StateMap array{period: string, from: string, to: string, refresh: int, service: string, env: string}
 * @phpstan-type StatePatch array{period?: string, from?: string, to?: string, refresh?: int, service?: string, env?: string}
 */
final class ViewState
{
    /**
     * Auto-refresh intervals offered by the header control, in seconds. 0 is
     * off. A remembered value outside this list is read as off, so a hand-edited
     * cookie can't set the dashboard polling every second.
     *
     * @var list<int>
     */
    public const INTERVALS = [0, 10, 30, 60];

    /**
     * Cookie name when `telemetry-ui.state.cookie` says nothing. Underscored
     * rather than hyphenated so it is a valid PHP variable name once parsed
     * into `$_COOKIE`.
     */
    public const DEFAULT_COOKIE = 'telemetry_ui_view';

    /** @var StateMap|null */
    private ?array $resolved = null;

    /**
     * Set by {@see put()}/{@see forget()} — the resolved state no longer matches
     * what the request arrived with, so it must be written back even if the URL
     * carried nothing.
     */
    private bool $dirty = false;

    /**
     * The request the memo above belongs to. This object is request-scoped, but
     * "request-scoped" is only enforced by the runtime that bothers to flush
     * scoped bindings (Octane does; a plain test harness handling two requests
     * against one container does not). Holding the request the answer was
     * computed for makes the memo self-invalidating either way, which matters:
     * a stale window silently re-scoping the next page is precisely the class
     * of bug this class exists to remove.
     */
    private ?Request $memoFor = null;

    public function __construct(
        private readonly ScopeLock $lock,
        private readonly Config $config,
    ) {}

    public function period(): Period
    {
        return Period::tryFrom($this->resolve()['period']) ?? Period::default();
    }

    /**
     * The custom range's start, as the reader expressed it (unix seconds or a
     * `now-1h` style expression) — empty when no custom range is active.
     */
    public function from(): string
    {
        return $this->resolve()['from'];
    }

    public function to(): string
    {
        return $this->resolve()['to'];
    }

    /**
     * Whether an absolute/zoomed range is overriding the preset period.
     */
    public function hasCustomRange(): bool
    {
        return $this->resolve()['from'] !== '' && $this->resolve()['to'] !== '';
    }

    /**
     * The active window, custom range first and the preset period otherwise —
     * the same rule {@see Card::range()} applies.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function range(): array
    {
        $from = TimeExpression::parse($this->from());
        $to = TimeExpression::parse($this->to());

        if ($from !== null && $to !== null && $from < $to) {
            return [$from, $to];
        }

        return $this->period()->range();
    }

    /**
     * Auto-refresh interval in seconds; 0 means off.
     *
     * Deliberately has no URL form. The interval is a property of the reader's
     * own session, not of the view being shared — a "Copy link" that made
     * someone else's dashboard start polling would be a surprise, not a feature.
     */
    public function refresh(): int
    {
        return $this->resolve()['refresh'];
    }

    public function service(): string
    {
        return $this->resolve()['service'];
    }

    public function environment(): string
    {
        return $this->resolve()['env'];
    }

    /**
     * The whole state materialised as query parameters — what "Copy link" pins
     * into the URL so the recipient sees the sender's view rather than their
     * own remembered one.
     *
     * The scope keys are present even when empty: `?service=` is an explicit
     * "all services", which is exactly what has to survive the trip. `refresh`
     * is excluded (see {@see refresh()}).
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        $params = ['period' => $this->resolve()['period']];

        if ($this->hasCustomRange()) {
            $params['from'] = $this->from();
            $params['to'] = $this->to();
        }

        return [...$params, 'service' => $this->service(), 'env' => $this->environment()];
    }

    /**
     * @return StateMap
     */
    public function toArray(): array
    {
        return $this->resolve();
    }

    /**
     * Move the view state from the host side — e.g. a desktop shell that offers
     * its own range picker in its chrome. Only the keys you pass change, each
     * validated the same way a URL parameter is, and the new state is written
     * back on this response.
     *
     * @param  StatePatch  $values
     */
    public function put(array $values): self
    {
        $current = $this->resolve();

        $range = array_key_exists('period', $values) || array_key_exists('from', $values) || array_key_exists('to', $values)
            ? $this->normaliseRange(
                (string) ($values['period'] ?? $current['period']),
                (string) ($values['from'] ?? ''),
                (string) ($values['to'] ?? ''),
            )
            : ['period' => $current['period'], 'from' => $current['from'], 'to' => $current['to']];

        $this->resolved = [
            ...$range,
            'refresh' => array_key_exists('refresh', $values) ? $this->normaliseRefresh((string) $values['refresh']) : $current['refresh'],
            'service' => array_key_exists('service', $values) ? $this->withinLock((string) $values['service'], $this->lock->services(), $this->lock->servicesLocked()) : $current['service'],
            'env' => array_key_exists('env', $values) ? $this->withinLock((string) $values['env'], $this->lock->environments(), $this->lock->environmentsLocked()) : $current['env'],
        ];

        $this->dirty = true;

        return $this;
    }

    /**
     * Drop everything remembered and fall back to the defaults.
     */
    public function forget(): self
    {
        $this->resolved = $this->defaults();
        $this->dirty = true;

        return $this;
    }

    /**
     * Whether the state may be remembered at all (`telemetry-ui.state.enabled`).
     * Off means the dashboard behaves exactly as it did before: URL or default,
     * nothing carried between requests.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('telemetry-ui.state.enabled', true);
    }

    public function cookieName(): string
    {
        return self::cookieNameFor($this->config);
    }

    /**
     * Resolvable without a request, so the service provider can except the
     * cookie from encryption at boot without instantiating this class (and
     * pinning one request's state under a persistent runtime).
     */
    public static function cookieNameFor(Config $config): string
    {
        $name = $config->get('telemetry-ui.state.cookie');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_COOKIE;
    }

    /**
     * Whether the resolved state differs from what the request arrived with —
     * the signal to write a cookie and fire {@see ViewStateChanged}. An
     * unchanged view sets no header and raises no event, so a reader paging
     * around one window is quiet.
     */
    public function changed(): bool
    {
        // Compared as normalised state, not as raw cookie text: the browser's
        // own write (the auto-refresh control) may order or omit keys
        // differently, and that is not a change the host should hear about.
        return $this->dirty || $this->resolve() !== $this->storedState();
    }

    /**
     * The state as the cookie's value: a query string, so the auto-refresh
     * control — the one control that changes state without navigating — can
     * read and rewrite a single key from JavaScript with `URLSearchParams`.
     */
    public function encode(): string
    {
        $state = $this->resolve();

        return http_build_query(array_filter([
            'period' => $state['period'],
            'from' => $state['from'],
            'to' => $state['to'],
            'refresh' => $state['refresh'] === 0 ? '' : (string) $state['refresh'],
            // Scope keys are kept even when empty: "" is a real, explicit
            // "all services" that has to be distinguishable from "unset".
            'service' => $state['service'],
            'env' => $state['env'],
        ], static fn (string $value, string $key): bool => $value !== '' || in_array($key, ['service', 'env'], true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The cookie carrying {@see encode()}. Not http-only on purpose — the
     * auto-refresh control writes it from the browser — and never a security
     * boundary; see the class docblock.
     */
    public function cookie(): Cookie
    {
        $attributes = $this->cookieAttributes();

        return new Cookie(
            name: $attributes['name'],
            value: $this->encode(),
            expire: time() + $attributes['maxAge'],
            path: $attributes['path'],
            domain: $attributes['domain'] === '' ? null : $attributes['domain'],
            secure: $attributes['secure'],
            httpOnly: false,
            sameSite: $attributes['sameSite'],
        );
    }

    /**
     * How the cookie is scoped, handed to the browser so the auto-refresh
     * control's own write lands on the SAME cookie rather than creating a
     * second one at a different path.
     *
     * Follows the app's session cookie settings, so a dashboard mounted on a
     * subdomain or behind TLS-only cookies inherits the right answer.
     *
     * @return array{name: string, path: string, domain: string, secure: bool, sameSite: 'lax'|'none'|'strict', maxAge: int}
     */
    public function cookieAttributes(): array
    {
        /** @var array<string, mixed> $session */
        $session = (array) $this->config->get('session', []);

        $sameSite = is_string($session['same_site'] ?? null) ? strtolower($session['same_site']) : '';

        return [
            'name' => $this->cookieName(),
            'path' => is_string($session['path'] ?? null) && $session['path'] !== '' ? $session['path'] : '/',
            'domain' => is_string($session['domain'] ?? null) ? $session['domain'] : '',
            'secure' => (bool) ($session['secure'] ?? false),
            'sameSite' => match ($sameSite) {
                Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
                Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
                default => Cookie::SAMESITE_LAX,
            },
            'maxAge' => 60 * $this->lifetime(),
        ];
    }

    /**
     * Cookie lifetime in minutes.
     */
    public function lifetime(): int
    {
        $default = 60 * 24 * 365;
        $minutes = $this->config->get('telemetry-ui.state.lifetime', $default);

        return max(1, is_numeric($minutes) ? (int) $minutes : $default);
    }

    /**
     * @return StateMap
     */
    private function resolve(): array
    {
        $request = $this->request();

        if ($this->memoFor !== $request) {
            $this->memoFor = $request;
            $this->resolved = null;
            $this->dirty = false;
        }

        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $query = $this->query();
        $stored = $this->enabled() ? $this->stored() : [];

        // The range is one unit — see the class docblock.
        $rangeSource = $this->hasAny($query, ['period', 'from', 'to']) ? $query : $stored;

        return $this->resolved = [
            ...$this->normaliseRange(
                $this->string($rangeSource, 'period'),
                $this->string($rangeSource, 'from'),
                $this->string($rangeSource, 'to'),
            ),
            // No URL form, so always the remembered value.
            'refresh' => $this->normaliseRefresh($this->string($stored, 'refresh')),
            // Scope dimensions move independently (the switcher changes one at
            // a time and leaves the other alone), so each falls back on its own.
            // A remembered value is bounded by the lock; a URL value is left
            // exactly as it was before this class existed and is fail-closed
            // downstream by ScopesQueries.
            'service' => array_key_exists('service', $query)
                ? $this->string($query, 'service')
                : $this->withinLock($this->string($stored, 'service'), $this->lock->services(), $this->lock->servicesLocked()),
            'env' => array_key_exists('env', $query)
                ? $this->string($query, 'env')
                : $this->withinLock($this->string($stored, 'env'), $this->lock->environments(), $this->lock->environmentsLocked()),
        ];
    }

    /**
     * @return StateMap
     */
    private function defaults(): array
    {
        return ['period' => Period::default()->value, 'from' => '', 'to' => '', 'refresh' => 0, 'service' => '', 'env' => ''];
    }

    /**
     * A half-open or backwards range is no range at all, so the preset period
     * stays in charge rather than the page rendering an empty window.
     *
     * @return array{period: string, from: string, to: string}
     */
    private function normaliseRange(string $period, string $from, string $to): array
    {
        $start = TimeExpression::parse($from);
        $end = TimeExpression::parse($to);
        $custom = $start !== null && $end !== null && $start < $end;

        return [
            'period' => (Period::tryFrom($period) ?? Period::default())->value,
            'from' => $custom ? $from : '',
            'to' => $custom ? $to : '',
        ];
    }

    private function normaliseRefresh(string $value): int
    {
        $seconds = (int) $value;

        return in_array($seconds, self::INTERVALS, true) ? $seconds : 0;
    }

    /**
     * A remembered scope may narrow within the tenancy lock but never reach
     * outside it: a value the lock no longer allows is dropped, so the picker
     * and the queries agree on "all of what you may see" instead of the picker
     * quietly showing one thing while the queries fail closed to another.
     *
     * @param  list<string>  $allowed
     */
    private function withinLock(string $value, array $allowed, bool $locked): string
    {
        if (! $locked) {
            return $value;
        }

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * The browser URL's query parameters.
     *
     * On a Livewire update (a lazy card mounting in its own request, an
     * auto-refresh tick) the request URL is Livewire's endpoint, so the page's
     * own parameters are read off the Referer — the same place Livewire's own
     * `#[Url]` attribute reads them from, so a card and its chrome can never
     * disagree about which window is on screen.
     *
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $request = $this->request();

        if (! $request->hasHeader('X-Livewire')) {
            return self::stringKeyed($request->query());
        }

        return self::parseQuery((string) parse_url((string) $request->header('Referer'), PHP_URL_QUERY));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $raw = $this->request()->cookie($this->cookieName());

        return self::parseQuery(is_string($raw) ? $raw : '');
    }

    /**
     * The request being served right now — read through the facade rather than
     * injected, so this object cannot pin one request's state (see $memoFor).
     */
    private function request(): Request
    {
        return RequestFacade::instance();
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseQuery(string $raw): array
    {
        parse_str($raw, $parsed);

        return self::stringKeyed($parsed);
    }

    /**
     * A `?0=x` style parameter parses to an INTEGER key, which the lookups here
     * don't allow for. Normalising costs nothing and keeps a hand-crafted URL
     * from being a type error.
     *
     * @param  array<mixed, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[is_string($key) ? $key : (string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * What the request arrived remembering, run through the same validation as
     * a live resolve so the two are comparable.
     *
     * @return StateMap
     */
    private function storedState(): array
    {
        if (! $this->enabled()) {
            return $this->defaults();
        }

        $stored = $this->stored();

        return [
            ...$this->normaliseRange(
                $this->string($stored, 'period'),
                $this->string($stored, 'from'),
                $this->string($stored, 'to'),
            ),
            'refresh' => $this->normaliseRefresh($this->string($stored, 'refresh')),
            'service' => $this->withinLock($this->string($stored, 'service'), $this->lock->services(), $this->lock->servicesLocked()),
            'env' => $this->withinLock($this->string($stored, 'env'), $this->lock->environments(), $this->lock->environmentsLocked()),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function hasAny(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
