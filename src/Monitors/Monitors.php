<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

use Cbox\TelemetryUi\Contracts\ManagesDirectives;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;

/**
 * Monitors: cboxdk/laravel-telemetry directives, placed from the dashboard
 * on the services it can reach, within the viewer's scope.
 *
 * A service is monitorable when this app IS that service and runs the core
 * (in-process, `monitors.local`), or when `monitors.services.<name>` gives
 * its base URL and directives secret (signed HTTP). Nothing is resolved at
 * boot: a client is built per call.
 *
 * The scope rule, on every read and write:
 *
 *  1. Only services the viewer's lock allows (and, with an environment lock,
 *     only targets whose environment it allows; unknown counts as no).
 *  2. A viewer with a context lock (`['contexts' => ['tenant.id' => ['7']]]`)
 *     may only monitor those exact context values. A route, path, job or
 *     user monitor is refused: a directive matches across every tenant, so
 *     "checkout.* for tenant 7" cannot be expressed, and "checkout.*" would
 *     record other tenants' traffic. Such a viewer sees only the monitors it
 *     could have placed, and can remove only those.
 */
final class Monitors
{
    public function __construct(
        private readonly Repository $config,
        private readonly ScopeLock $lock,
        private readonly Factory $http,
        private readonly Container $container,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('telemetry-ui.monitors.enabled', true) && $this->services() !== [];
    }

    /**
     * The services this viewer may monitor, and how each is reached.
     *
     * @return list<array{service: string, transport: 'local'|'http', environment: string|null}>
     */
    public function services(): array
    {
        if (! (bool) $this->config->get('telemetry-ui.monitors.enabled', true)) {
            return [];
        }

        $out = [];

        foreach ($this->targets() as $service => $target) {
            if ($this->allowedService($service, $target['environment'])) {
                $out[] = ['service' => $service, 'transport' => $target['transport'], 'environment' => $target['environment']];
            }
        }

        return $out;
    }

    public function allows(string $service): bool
    {
        return in_array($service, array_column($this->services(), 'service'), true);
    }

    /**
     * Every active monitor this viewer may see, newest expiry last. A service
     * that cannot be read is reported, not fatal.
     *
     * @return array{monitors: list<Monitor>, errors: array<string, string>}
     */
    public function list(?string $service = null): array
    {
        $monitors = [];
        $errors = [];

        foreach ($this->services() as $target) {
            if ($service !== null && $target['service'] !== $service) {
                continue;
            }

            try {
                foreach ($this->client($target['service'])->all() as $monitor) {
                    if (! $monitor->expired() && $this->refusal($monitor->service, $monitor->match, $monitor->key, $monitor->value) === null) {
                        $monitors[] = $monitor;
                    }
                }
            } catch (MonitorException $e) {
                $errors[$target['service']] = $e->getMessage();
            }
        }

        usort($monitors, static fn (Monitor $a, Monitor $b): int => $a->expiresAt <=> $b->expiresAt);

        return ['monitors' => $monitors, 'errors' => $errors];
    }

    /**
     * @throws MonitorException
     */
    public function add(string $service, MonitorRequest $request): Monitor
    {
        $refusal = $this->refusal($service, $request->match, $request->key, $request->value);

        if ($refusal !== null) {
            throw MonitorException::forbidden($refusal);
        }

        return $this->client($service)->add($request);
    }

    /**
     * @throws MonitorException
     */
    public function remove(string $service, string $id): void
    {
        if (! $this->allows($service)) {
            throw MonitorException::forbidden("You cannot manage monitors on {$service}.");
        }

        $client = $this->client($service);

        // Only a monitor this viewer can see is one it may remove: a tenant
        // must not be able to switch off another tenant's by guessing an id.
        $monitor = array_values(array_filter($client->all(), static fn (Monitor $m): bool => $m->id === $id))[0] ?? null;

        if ($monitor === null || $this->refusal($service, $monitor->match, $monitor->key, $monitor->value) !== null) {
            throw MonitorException::notFound("No active monitor {$id} on {$service}.");
        }

        if (! $client->remove($id)) {
            throw MonitorException::notFound("No active monitor {$id} on {$service}.");
        }
    }

    /**
     * Why this viewer may not monitor this, or null when it may.
     */
    public function refusal(string $service, string $match, ?string $key, string $value): ?string
    {
        if (! $this->allows($service)) {
            return "You cannot place monitors on {$service}.";
        }

        $contexts = $this->lock->contexts();

        if ($contexts === null) {
            return null;
        }

        $scope = implode(', ', array_keys($contexts)) ?: 'nothing';

        if ($match !== MonitorRequest::CONTEXT) {
            return "Your view is limited to {$scope}: a {$match} monitor would record every tenant's traffic, so only a monitor on your own {$scope} can be placed.";
        }

        if ($key === null || ! in_array($value, $contexts[$key] ?? [], true)) {
            return "Your view is limited to {$scope}: {$key} = {$value} is outside it.";
        }

        return null;
    }

    /**
     * Who placed it: the dashboard user's name and email, as the core's
     * `created_by` (at most 100 characters).
     */
    public static function actor(mixed $user): string
    {
        $name = is_object($user) ? data_get($user, 'name') : null;
        $email = is_object($user) ? data_get($user, 'email') : null;
        $name = is_string($name) && $name !== '' ? $name : null;
        $email = is_string($email) && $email !== '' ? $email : null;

        return match (true) {
            $name !== null && $email !== null => "{$name} <{$email}>",
            default => $name ?? $email ?? 'telemetry-ui',
        };
    }

    /**
     * @throws MonitorException
     */
    public function client(string $service): ManagesDirectives
    {
        $target = $this->targets()[$service] ?? null;

        if ($target === null) {
            throw MonitorException::notFound("{$service} is not a monitored service.");
        }

        if ($target['transport'] === 'local') {
            return new LocalDirectives($service, fn (): object => $this->localStore() ?? throw new MonitorException('cboxdk/laravel-telemetry directives are not available in this app.'));
        }

        return new SignedHttpDirectives(
            $this->http,
            $service,
            (string) $target['url'],
            (string) $target['secret'],
            $target['path'],
            (float) $this->config->get('telemetry-ui.monitors.timeout', 5.0),
        );
    }

    /**
     * @return array<string, array{transport: 'local'|'http', environment: string|null, url: string|null, secret: string|null, path: string}>
     */
    private function targets(): array
    {
        $targets = [];

        $configured = $this->config->get('telemetry-ui.monitors.services', []);

        foreach (is_array($configured) ? $configured : [] as $service => $target) {
            $url = is_array($target) ? ($target['url'] ?? null) : null;
            $secret = is_array($target) ? ($target['secret'] ?? null) : null;

            if (! is_string($service) || ! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
                continue;
            }

            $path = $target['path'] ?? null;
            $environment = $target['environment'] ?? null;

            $targets[$service] = [
                'transport' => 'http',
                'environment' => is_string($environment) && $environment !== '' ? $environment : null,
                'url' => $url,
                'secret' => $secret,
                'path' => is_string($path) && $path !== '' ? $path : 'telemetry/directives',
            ];
        }

        // This app itself, when it runs the core with directives on. Wins
        // over a configured URL for the same name: no network, no secret.
        $local = $this->localService();

        if ($local !== null) {
            $environment = $this->config->get('telemetry.service.environment');

            $targets[$local] = [
                'transport' => 'local',
                'environment' => is_string($environment) && $environment !== '' ? $environment : null,
                'url' => null,
                'secret' => null,
                'path' => '',
            ];
        }

        return $targets;
    }

    private function localService(): ?string
    {
        if (! (bool) $this->config->get('telemetry-ui.monitors.local', true)
            || ! (bool) $this->config->get('telemetry.directives.enabled', true)
            || ! class_exists('Cbox\\Telemetry\\Directives\\DirectiveStore')) {
            return null;
        }

        $name = $this->config->get('telemetry.service.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function localStore(): ?object
    {
        $class = 'Cbox\\Telemetry\\TelemetryManager';

        if (! $this->container->bound($class)) {
            return null;
        }

        $manager = $this->container->make($class);

        if (! is_object($manager) || ! method_exists($manager, 'directives')) {
            return null;
        }

        $store = $manager->directives();

        return is_object($store) ? $store : null;
    }

    private function allowedService(string $service, ?string $environment): bool
    {
        if ($this->lock->servicesLocked() && ! in_array($service, $this->lock->services(), true)) {
            return false;
        }

        if ($this->lock->environmentsLocked() && ($environment === null || ! in_array($environment, $this->lock->environments(), true))) {
            return false;
        }

        return true;
    }
}
