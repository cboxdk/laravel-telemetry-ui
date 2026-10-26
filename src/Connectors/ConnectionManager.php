<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Cbox\TelemetryUi\Connectors\GitHub\GitHubSource;
use Cbox\TelemetryUi\Connectors\Linear\LinearSource;
use Cbox\TelemetryUi\Connectors\Loki\LokiSource;
use Cbox\TelemetryUi\Connectors\Prometheus\MimirSource;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\Prometheus\WindowSplitter;
use Cbox\TelemetryUi\Connectors\Sentry\SentrySource;
use Cbox\TelemetryUi\Connectors\Tempo\TempoSource;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\ProbesConnection;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Resolves named backend connections lazily from config. Nothing is
 * instantiated until a source is actually used.
 */
class ConnectionManager
{
    /**
     * Upper bound on the driver cache, so a many-tenant Octane worker can't grow
     * it without limit (see {@see cached()}).
     */
    private const MAX_CACHED_DRIVERS = 64;

    /**
     * @var array<string, Closure(array<string, mixed>): object>
     */
    private array $customCreators = [];

    /**
     * Config keys whose values are credentials. They feed {@see fingerprint()}
     * only as a hash, never as the value itself.
     */
    private const SECRET_KEYS = ['token', 'basic_auth', 'headers', 'password', 'secret', 'api_key'];

    /**
     * @var array<string, object>
     */
    private array $resolved = [];

    public function __construct(private readonly Config $config) {}

    /**
     * A stable, opaque id for the connections in force right now: the driver,
     * url, tenant and other settings of every connection, with credentials
     * reduced to a hash. Two viewers get the same fingerprint only when they
     * read the same backends with the same credentials.
     *
     * Every cache that holds what a backend answered is keyed under it (see
     * {@see cacheKey()}), so one tenant's cached answer is never served to
     * another — the cache store is shared by every worker and every request,
     * not just by one Octane process. It is a hash, so it is safe in a cache
     * key; it never contains a token, a password or a header value.
     */
    public function fingerprint(): string
    {
        $names = array_keys((array) $this->config->get('telemetry-ui.connections', []));
        $names = [...$names, ...array_keys(app(ResolvedConnections::class)->all())];
        $names = array_values(array_unique(array_map(strval(...), $names)));
        sort($names);

        $material = [];

        foreach ($names as $name) {
            $material[$name] = $this->identity($this->connectionConfig($name));
        }

        return substr(hash('sha256', (string) json_encode($material, JSON_PARTIAL_OUTPUT_ON_ERROR)), 0, 32);
    }

    /**
     * A cache key namespaced to the connections in force ({@see fingerprint()}):
     * `telemetry-ui:<fingerprint>:<key>`. Use it for anything cached from a
     * backend's answer, including in custom drivers and panels.
     */
    public function cacheKey(string $key): string
    {
        return 'telemetry-ui:'.$this->fingerprint().':'.$key;
    }

    public function metrics(?string $name = null): MetricsSource
    {
        return $this->connection($name ?? 'metrics', MetricsSource::class);
    }

    public function traces(?string $name = null): TracesSource
    {
        return $this->connection($name ?? 'traces', TracesSource::class);
    }

    public function logs(?string $name = null): LogsSource
    {
        return $this->connection($name ?? 'logs', LogsSource::class);
    }

    public function issues(?string $name = null): IssuesSource
    {
        if ($name !== null && $name !== 'issues') {
            return $this->connection($name, IssuesSource::class);
        }

        $sources = $this->issueSources();

        if ($sources === []) {
            throw new InvalidArgumentException('Telemetry UI connection [issues] is not configured.');
        }

        return $sources[0]['source'];
    }

    /**
     * All configured issue trackers. `connections.issues` may be a single
     * connection (a map with a "driver") or a list of them — so one project's
     * frontend, api and sidecar repos surface together. Each carries a label
     * (its config "label", or the driver's own).
     *
     * @return list<array{key: string, label: string, source: IssuesSource}>
     */
    public function issueSources(): array
    {
        $config = $this->connectionConfig('issues');

        if ($config === null || $config === []) {
            return [];
        }

        // A single connection has a top-level "driver"; otherwise it's a list.
        $single = isset($config['driver']);
        $repos = $single ? ['issues' => $config] : $config;

        $sources = [];
        $labelCounts = [];

        foreach ($repos as $key => $repoConfig) {
            if (! is_array($repoConfig) || ($repoConfig['driver'] ?? null) === null) {
                continue;
            }

            // In a multi-repo list, one misconfigured repo (bad driver, missing
            // token/repo) must not take the whole Issues page down — skip it and
            // list the trackers that do build. A single tracker still surfaces
            // its config error (the caller decides how to render it).
            try {
                $source = $this->cached('issues['.$key.']', $repoConfig);
            } catch (\Throwable $e) {
                if ($single) {
                    throw $e;
                }

                continue;
            }

            if (! $source instanceof IssuesSource) {
                continue;
            }

            $label = is_string($repoConfig['label'] ?? null) && $repoConfig['label'] !== ''
                ? $repoConfig['label']
                : $source->label();

            // Labels double as the repo filter key, so two label-less repos on
            // the same driver (both "GitHub") must not collide — suffix repeats.
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
            if ($labelCounts[$label] > 1) {
                $label .= ' ('.$labelCounts[$label].')';
            }

            $sources[] = ['key' => (string) $key, 'label' => $label, 'source' => $source];
        }

        return $sources;
    }

    /**
     * Whether at least one issue tracker is configured (drives the Issues
     * page). Config-only — it does not build the driver, so a present-but-
     * misconfigured connection still reports as configured (and surfaces its
     * error when actually used).
     */
    public function hasIssues(?string $name = null): bool
    {
        // Resolver-aware (like issues()/issueSources()), so page registration and
        // the create-ticket gate agree with the tracker actually resolved for the
        // viewer — not a static-config-only view that disagrees per tenant.
        $config = $this->connectionConfig($name ?? 'issues');

        if (! is_array($config) || $config === []) {
            return false;
        }

        // Single connection (top-level driver) or a list of connections.
        // isset() is false when driver is null/absent, so a present key means
        // a configured single connection.
        if (isset($config['driver'])) {
            return true;
        }

        foreach ($config as $repo) {
            if (is_array($repo) && ($repo['driver'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the primary tracker can create issues (GitHub, Linear) — gates
     * the "create ticket" affordances. Creation targets the first source.
     */
    public function canCreateIssues(?string $name = null): bool
    {
        return $this->hasIssues($name) && $this->issues($name) instanceof CreatesIssues;
    }

    /**
     * Test a named connection and report precisely what is wrong — reachability,
     * TLS, credentials, or "this answered, but it isn't the API we expect".
     *
     * Never throws: every failure mode, including a missing or malformed
     * connection config, comes back as a non-Ok {@see ProbeResult}. A connection
     * tester that can itself blow up is not a tester.
     */
    public function probe(string $name): ProbeResult
    {
        try {
            $config = $this->connectionConfig($name);

            if ($config === null) {
                return ProbeResult::fail(
                    BackendStatus::Error,
                    "Connection [{$name}] is not configured.",
                );
            }

            $source = $this->cached($name, $config);
        } catch (SourceException $exception) {
            return ProbeResult::fromException($exception);
        } catch (InvalidArgumentException $exception) {
            // Config-shaped failure (no driver, no url, unknown driver). The
            // message is ours, not a backend's, so it is safe to surface.
            return ProbeResult::fail(BackendStatus::Error, $exception->getMessage());
        }

        if (! $source instanceof ProbesConnection) {
            return ProbeResult::fail(
                BackendStatus::Error,
                'This driver does not support connection testing.',
            );
        }

        try {
            return $source->probe();
        } catch (SourceException $exception) {
            // ProbesConnection forbids throwing; a driver that does anyway must
            // not take the connection screen down with it.
            return ProbeResult::fromException($exception);
        }
    }

    /**
     * Register a custom driver, e.g. TelemetryUi's connection manager can be
     * taught "victoriametrics" by a third-party package.
     *
     * @param  Closure(array<string, mixed>): object  $creator
     */
    public function extend(string $driver, Closure $creator): void
    {
        $this->customCreators[$driver] = $creator;
    }

    /**
     * @template TSource of object
     *
     * @param  class-string<TSource>  $contract
     * @return TSource
     */
    private function connection(string $name, string $contract): object
    {
        $config = $this->connectionConfig($name);

        if ($config === null) {
            throw new InvalidArgumentException("Telemetry UI connection [{$name}] is not configured.");
        }

        $source = $this->cached($name, $config);

        if (! $source instanceof $contract) {
            throw new InvalidArgumentException(
                "Telemetry UI connection [{$name}] does not implement [{$contract}].",
            );
        }

        return $source;
    }

    /**
     * The effective config for a connection: the per-viewer resolver's value
     * (multi-tenant hosting) if it supplies one, otherwise the static config.
     *
     * @return array<string, mixed>|null
     */
    private function connectionConfig(string $name): ?array
    {
        // The per-viewer resolver (multi-tenant hosting) is memoised for the
        // request, so a page of cards resolves the tenant once, not per backend.
        $resolved = app(ResolvedConnections::class)->config($name);

        if ($resolved !== null) {
            return $resolved;
        }

        $config = $this->config->get("telemetry-ui.connections.{$name}");

        return is_array($config) ? $this->stringKeyed($config) : null;
    }

    /**
     * What {@see fingerprint()} hashes for one connection: its settings, with
     * every credential replaced by a hash of it.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function identity(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        // A set of connections (multi-repo issues) is hashed entry by entry.
        if (! isset($config['driver']) && $config !== [] && array_filter($config, is_array(...)) === $config) {
            return array_map(
                fn (array $entry): ?array => $this->identity($this->stringKeyed($entry)),
                $config,
            );
        }

        foreach (self::SECRET_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                $config[$key] = hash('sha256', (string) json_encode($config[$key], JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        }

        ksort($config);

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private function stringKeyed(array $config): array
    {
        $keyed = [];

        foreach ($config as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * Build (once) and cache a driver, keyed by name AND config — so a per-tenant
     * resolver never hands one tenant another tenant's cached driver, even when
     * this manager is a singleton under a persistent runtime like Octane.
     *
     * @param  array<string, mixed>  $config
     */
    private function cached(string $name, array $config): object
    {
        // Key on a JSON projection, not serialize() — a per-tenant resolver may
        // return config carrying a closure or resource (a lazy token, a Guzzle
        // handler), and serialize() throws an uncatchable Error on those, 500-ing
        // the page. json_encode degrades (unencodable leaves collapse) instead.
        $key = $name.':'.md5((string) json_encode($config));

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        // Bound the cache: this manager is a process-lived singleton, so under a
        // many-tenant Octane worker the per-(name, config) driver map would grow
        // without limit. Drop the oldest entry once past the cap — drivers are
        // cheap to rebuild, and a hot tenant is re-cached on its next access.
        if (count($this->resolved) >= self::MAX_CACHED_DRIVERS) {
            array_shift($this->resolved);
        }

        return $this->resolved[$key] = $this->build($config, $name);
    }

    /**
     * Build a driver from an inline config map (used by named connections and
     * by each entry of a multi-repo issues list).
     *
     * @param  array<string, mixed>  $config
     */
    private function build(array $config, string $name): object
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException("Telemetry UI connection [{$name}] has no driver.");
        }

        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($config);
        }

        return match ($driver) {
            'prometheus' => new PrometheusSource($this->client($config), $this->prefix($config), $this->splitter($config, 0)),
            'mimir' => new MimirSource($this->client($config), $this->prefix($config, 'prometheus'), $this->splitter($config, MimirSource::SPLIT_AFTER)),
            'tempo' => new TempoSource($this->client($config), max(0, (int) ($config['max_search'] ?? 604_800)), max(1, (int) ($config['spans_per_trace'] ?? 100))),
            'loki' => new LokiSource($this->client($config)),
            'github' => $this->github($config),
            'sentry' => $this->sentry($config),
            'linear' => $this->linear($config),
            default => throw new InvalidArgumentException("Telemetry UI driver [{$driver}] is not supported."),
        };
    }

    /**
     * When a window-wide read runs as a sliced range query: after
     * `split_after` seconds (the driver's default when unset — on for Mimir,
     * off for Prometheus), in `split_slice`-second slices, at most
     * `split_max_slices` of them before they grow (up to a day).
     *
     * @param  array<string, mixed>  $config
     */
    private function splitter(array $config, int $defaultAfter): WindowSplitter
    {
        $after = $config['split_after'] ?? null;
        $slice = $config['split_slice'] ?? null;
        $slices = $config['split_max_slices'] ?? null;

        return new WindowSplitter(
            is_numeric($after) ? (int) $after : $defaultAfter,
            is_numeric($slice) && (int) $slice > 0 ? (int) $slice : 3_600,
            is_numeric($slices) && (int) $slices > 0 ? (int) $slices : 168,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sentry(array $config): SentrySource
    {
        $org = $config['org'] ?? $config['organization'] ?? null;
        $project = $config['project'] ?? null;

        if (! is_string($org) || $org === '' || ! is_string($project) || $project === '') {
            throw new InvalidArgumentException('Sentry issues connection needs "org" and "project".');
        }

        $base = is_string($config['url'] ?? null) && $config['url'] !== '' ? $config['url'] : 'https://sentry.io';
        $config['url'] = $base;

        return new SentrySource($this->client($config), $org, $project, $base);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function linear(array $config): LinearSource
    {
        $config['url'] ??= 'https://api.linear.app';

        // Linear authenticates with the raw API key (not a Bearer token).
        $token = $config['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $config['headers'] = array_merge(['Authorization' => $token], is_array($config['headers'] ?? null) ? $config['headers'] : []);
            unset($config['token']);
        }

        $team = $config['team'] ?? null;
        $teamId = $config['team_id'] ?? null;

        return new LinearSource(
            $this->client($config),
            is_string($team) ? $team : null,
            is_string($teamId) ? $teamId : null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function github(array $config): GitHubSource
    {
        $repo = $config['repo'] ?? null;

        if (! is_string($repo) || ! str_contains($repo, '/')) {
            throw new InvalidArgumentException('GitHub issues connection needs a "repo" as "owner/name".');
        }

        // GitHub wants its own Accept + API-version headers; the token becomes
        // an Authorization header via client() below.
        $config['url'] ??= 'https://api.github.com';
        $config['headers'] = array_merge([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'cboxdk-laravel-telemetry-ui',
        ], is_array($config['headers'] ?? null) ? $config['headers'] : []);

        return new GitHubSource($this->client($config), $repo);
    }

    /**
     * Build a fully-wired ApiClient from a connection config array — Bearer/
     * basic auth, tenancy header, timeout, query cache and retries all applied.
     * Public so custom drivers registered via {@see extend()} can reuse the
     * same wiring instead of constructing ApiClient by hand.
     *
     * @param  array<string, mixed>  $config
     */
    public function client(array $config): ApiClient
    {
        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('Telemetry UI connection has no url.');
        }

        /** @var array<string, string> $headers */
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];

        // A Bearer token (e.g. a Grafana service account) or basic_auth
        // "user:pass" becomes an Authorization header unless one is already
        // set explicitly.
        if (! isset($headers['Authorization'])) {
            $token = $config['token'] ?? null;
            $basic = $config['basic_auth'] ?? null;

            if (is_string($token) && $token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            } elseif (is_string($basic) && str_contains($basic, ':')) {
                $headers['Authorization'] = 'Basic '.base64_encode($basic);
            }
        }

        $tenant = $config['tenant'] ?? null;

        // A per-connection "cache" overrides the global default; 0 disables.
        $ttl = $config['cache'] ?? $this->config->get('telemetry-ui.cache.ttl', 0);

        return new ApiClient(
            url: $url,
            headers: $headers,
            tenant: is_string($tenant) && $tenant !== '' ? $tenant : null,
            timeout: (float) ($config['timeout'] ?? 10.0),
            cacheTtl: is_numeric($ttl) ? (int) $ttl : 0,
            retries: (int) $this->config->get('telemetry-ui.retries', 2),
            verify: $this->verify($config),
        );
    }

    /**
     * TLS peer verification for a connection: true (default), false to disable,
     * or a path to a custom CA bundle — for backends behind an internal PKI or
     * a self-signed certificate.
     *
     * Only an explicit boolean false disables verification. A stray string like
     * "false" or "0" from an env var is NOT accepted as "off": silently
     * downgrading TLS on a typo is exactly the failure this guards against, so
     * anything unrecognised falls back to verifying.
     *
     * @param  array<string, mixed>  $config
     */
    private function verify(array $config): bool|string
    {
        $verify = $config['verify'] ?? true;

        if ($verify === false) {
            return false;
        }

        // A non-empty string is a CA bundle path.
        if (is_string($verify) && $verify !== '') {
            return $verify;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function prefix(array $config, string $default = ''): string
    {
        $prefix = $config['prefix'] ?? null;

        return is_string($prefix) ? $prefix : $default;
    }
}
