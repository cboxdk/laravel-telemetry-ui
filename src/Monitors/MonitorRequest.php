<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

use Illuminate\Support\Str;

/**
 * A monitor to place, validated before it leaves this app: what to match,
 * for how long, whether to profile, why, and who asked.
 */
final readonly class MonitorRequest
{
    public const ROUTE = 'route';

    public const PATH = 'path';

    public const USER = 'user';

    public const CONTEXT = 'context';

    public const JOB = 'job';

    public const MATCHES = [self::ROUTE, self::PATH, self::USER, self::CONTEXT, self::JOB];

    /** The core caps a directive at `directives.max_minutes` (a day by default); so does this. */
    public const MAX_MINUTES = 1440;

    private function __construct(
        public string $match,
        public ?string $key,
        public string $value,
        public int $minutes,
        public bool $profile,
        public ?string $reason,
        public ?string $createdBy,
    ) {}

    /**
     * @throws MonitorException when the request is not one the core would accept
     */
    public static function make(string $match, ?string $key, string $value, int $minutes, bool $profile = false, ?string $reason = null, ?string $createdBy = null): self
    {
        $match = strtolower(trim($match));
        $key = $key === null ? null : trim($key);
        $value = trim($value);

        if (! in_array($match, self::MATCHES, true)) {
            throw MonitorException::invalid('A monitor matches one of: '.implode(', ', self::MATCHES).'.');
        }

        if ($match === self::CONTEXT && ($key === null || preg_match('/^[a-z][a-z0-9._]*$/', $key) !== 1)) {
            throw MonitorException::invalid('A context monitor needs the dimension it matches on, e.g. tenant.id.');
        }

        if ($value === '' || mb_strlen($value) > 200) {
            throw MonitorException::invalid('A monitor needs a value to match (at most 200 characters).');
        }

        // "Every route" is a sample rate of 100%, not a monitor.
        if (in_array($match, [self::ROUTE, self::PATH, self::JOB], true) && trim($value, '*/ ') === '') {
            throw MonitorException::invalid('A pattern that matches everything is not a monitor: narrow it to a route, path or job.');
        }

        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw MonitorException::invalid('A monitor runs for 1 to '.self::MAX_MINUTES.' minutes.');
        }

        return new self(
            match: $match,
            key: $match === self::CONTEXT ? $key : null,
            value: $value,
            minutes: $minutes,
            profile: $profile,
            reason: $reason === null || trim($reason) === '' ? null : Str::limit(trim($reason), 200, ''),
            createdBy: $createdBy === null || trim($createdBy) === '' ? null : Str::limit(trim($createdBy), 100, ''),
        );
    }

    /**
     * The body the core's endpoint takes (`DirectivesController::store`).
     *
     * @return array{match: string, value: string, minutes: int, profile: bool, key?: string, reason?: string, created_by?: string}
     */
    public function payload(): array
    {
        return array_filter([
            'match' => $this->match,
            'key' => $this->key,
            'value' => $this->value,
            'minutes' => $this->minutes,
            'profile' => $this->profile,
            'reason' => $this->reason,
            'created_by' => $this->createdBy,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
