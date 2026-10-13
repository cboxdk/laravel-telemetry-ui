<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

/**
 * A directive as the monitored app reports it, plus which service it is on.
 * Spans recorded because of it carry `telemetry.directive = <id>`.
 */
final readonly class Monitor
{
    public function __construct(
        public string $id,
        public string $service,
        public string $match,
        public ?string $key,
        public string $value,
        public bool $profile,
        public int $expiresAt,
        public ?string $reason,
        public ?string $createdBy,
    ) {}

    /**
     * The core's own shape (`Directive::toArray()`, and the HTTP endpoint's).
     *
     * @param  array<mixed>  $data
     */
    public static function fromDirective(string $service, array $data): ?self
    {
        $id = $data['id'] ?? null;
        $match = $data['match'] ?? null;
        $value = $data['value'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($match) || ! is_scalar($value)) {
            return null;
        }

        return new self(
            id: $id,
            service: $service,
            match: $match,
            key: is_string($data['key'] ?? null) ? $data['key'] : null,
            value: (string) $value,
            profile: (bool) ($data['profile'] ?? false),
            expiresAt: is_numeric($data['expires_at'] ?? null) ? (int) $data['expires_at'] : 0,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
            createdBy: is_string($data['created_by'] ?? null) ? $data['created_by'] : null,
        );
    }

    public function expired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? time());
    }

    /** "route checkout.*", "user 42", "tenant.id = 7". */
    public function describe(): string
    {
        return $this->match === MonitorRequest::CONTEXT
            ? "{$this->key} = {$this->value}"
            : "{$this->match} {$this->value}";
    }

    /**
     * @return array{id: string, service: string, match: string, key: string|null, value: string, profile: bool, expiresAt: int, reason: string|null, createdBy: string|null, describe: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'match' => $this->match,
            'key' => $this->key,
            'value' => $this->value,
            'profile' => $this->profile,
            'expiresAt' => $this->expiresAt,
            'reason' => $this->reason,
            'createdBy' => $this->createdBy,
            'describe' => $this->describe(),
        ];
    }
}
