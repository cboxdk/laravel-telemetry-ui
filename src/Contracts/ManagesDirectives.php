<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Monitors\Monitor;
use Cbox\TelemetryUi\Monitors\MonitorException;
use Cbox\TelemetryUi\Monitors\MonitorRequest;

/**
 * One monitored service's directives (cboxdk/laravel-telemetry 3.3+): the
 * time-limited rules that force a slice of its traffic to be kept, with
 * detail and optionally a CPU profile. Two ship: in-process, when the core
 * runs in this same app, and its signed HTTP endpoint, for an app elsewhere.
 */
interface ManagesDirectives
{
    /**
     * @return list<Monitor>
     *
     * @throws MonitorException
     */
    public function all(): array;

    /**
     * @throws MonitorException
     */
    public function add(MonitorRequest $request): Monitor;

    /**
     * @throws MonitorException
     */
    public function remove(string $id): bool;
}
