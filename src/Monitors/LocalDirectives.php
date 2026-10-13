<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

use Cbox\TelemetryUi\Contracts\ManagesDirectives;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Directives in this very app, through `Telemetry::directives()`, when
 * cboxdk/laravel-telemetry runs alongside the dashboard and the monitored
 * service is this app. No HTTP, no secret.
 *
 * The core's classes are reached through the store it hands back rather
 * than imported, so this package keeps working against a core release that
 * predates directives (it is then simply not offered).
 */
final class LocalDirectives implements ManagesDirectives
{
    private const DIRECTIVE = 'Cbox\\Telemetry\\Directives\\Directive';

    /**
     * @param  Closure(): object  $store  resolves the core's DirectiveStore
     */
    public function __construct(
        private readonly string $service,
        private readonly Closure $store,
    ) {}

    public function all(): array
    {
        $directives = $this->call('all');

        return array_values(array_filter(array_map(
            fn (mixed $d): ?Monitor => is_object($d) && method_exists($d, 'toArray') ? Monitor::fromDirective($this->service, (array) $d->toArray()) : null,
            is_array($directives) ? $directives : [],
        )));
    }

    public function add(MonitorRequest $request): Monitor
    {
        if (! class_exists(self::DIRECTIVE)) {
            throw new MonitorException('This app\'s cboxdk/laravel-telemetry has no directives (3.5+).');
        }

        $payload = $request->payload();
        $data = ['id' => null, 'expires_at' => time() + $request->minutes * 60] + $payload;

        try {
            /** @var callable(array<string, mixed>): object $fromArray */
            $fromArray = [self::DIRECTIVE, 'fromArray'];
            $placed = $this->call('add', $fromArray($data));
        } catch (InvalidArgumentException $e) {
            throw MonitorException::invalid($e->getMessage());
        }

        $monitor = is_object($placed) && method_exists($placed, 'toArray')
            ? Monitor::fromDirective($this->service, (array) $placed->toArray())
            : null;

        return $monitor ?? throw new MonitorException('The directive store answered without a directive.');
    }

    public function remove(string $id): bool
    {
        return $this->call('remove', $id) === true;
    }

    private function call(string $method, mixed ...$args): mixed
    {
        try {
            $store = ($this->store)();

            if (! method_exists($store, $method)) {
                throw new MonitorException('This app\'s cboxdk/laravel-telemetry has no directives (3.5+).');
            }

            return $store->{$method}(...$args);
        } catch (MonitorException|InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MonitorException('The directive store is unavailable: '.$e->getMessage(), previous: $e);
        }
    }
}
