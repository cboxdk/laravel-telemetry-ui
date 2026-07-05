<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Cbox\TelemetryUi\Connectors\GitHub\GitHubSource;
use Cbox\TelemetryUi\Connectors\Linear\LinearSource;
use Cbox\TelemetryUi\Connectors\Loki\LokiSource;
use Cbox\TelemetryUi\Connectors\Prometheus\MimirSource;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\Sentry\SentrySource;
use Cbox\TelemetryUi\Connectors\Tempo\TempoSource;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\TelemetryUiManager;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Resolves named backend connections lazily from config. Nothing is
 * instantiated until a source is actually used.
 */
final class ConnectionManager
{
    /**
     * @var array<string, Closure(array<string, mixed>): object>
     */
    private array $customCreators = [];

    /**
     * @var array<string, object>
     */
    private array $resolved = [];

    public function __construct(private readonly Config $config) {}

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
        $resolver = app(TelemetryUiManager::class)->connectionResolver();

        // Only consult the per-viewer resolver when there IS a viewer — an
        // unauthenticated request (or boot-time page registration) must fall
        // through to static config, never dereference a null user or resolve one
        // tenant's backends for a request that has no user.
        if ($resolver !== null && ($user = Auth::user()) !== null) {
            $resolved = $resolver($user);

            if (is_array($resolved) && is_array($resolved[$name] ?? null)) {
                /** @var array<string, mixed> */
                return $resolved[$name];
            }
        }

        $config = $this->config->get("telemetry-ui.connections.{$name}");

        return is_array($config) ? $config : null;
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

        return $this->resolved[$key] ??= $this->build($config, $name);
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
            'prometheus' => new PrometheusSource($this->client($config), $this->prefix($config)),
            'mimir' => new MimirSource($this->client($config), $this->prefix($config, 'prometheus')),
            'tempo' => new TempoSource($this->client($config)),
            'loki' => new LokiSource($this->client($config)),
            'github' => $this->github($config),
            'sentry' => $this->sentry($config),
            'linear' => $this->linear($config),
            default => throw new InvalidArgumentException("Telemetry UI driver [{$driver}] is not supported."),
        };
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
        );
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
