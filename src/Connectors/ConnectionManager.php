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
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
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
        return $this->connection($name ?? 'issues', IssuesSource::class);
    }

    /**
     * Whether an issues connection is configured (drives the Issues page).
     */
    public function hasIssues(?string $name = null): bool
    {
        $config = $this->config->get('telemetry-ui.connections.'.($name ?? 'issues'));

        return is_array($config) && ($config['driver'] ?? null) !== null;
    }

    /**
     * Whether the configured tracker can create issues (GitHub, Linear) —
     * gates the "create ticket" affordances.
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
        $source = $this->resolved[$name] ??= $this->resolve($name);

        if (! $source instanceof $contract) {
            throw new InvalidArgumentException(
                "Telemetry UI connection [{$name}] does not implement [{$contract}].",
            );
        }

        return $source;
    }

    private function resolve(string $name): object
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("telemetry-ui.connections.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Telemetry UI connection [{$name}] is not configured.");
        }

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
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): ApiClient
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
