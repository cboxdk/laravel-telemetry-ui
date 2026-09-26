<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

/**
 * One thing this app talks to, tied to the exporter instance that describes
 * it — plus how we decided they were the same thing, because a confident
 * wrong match is worse than no match at all.
 */
final readonly class DiscoveredTarget
{
    /**
     * @param  string  $name  what the app calls it: `web-3`, `cache-1:6379`
     * @param  string  $selector  the PromQL label matcher that pins this instance
     * @param  list<string>  $signals  catalogue signal keys that returned data
     * @param  list<string>  $absent  signal keys that returned nothing
     */
    public function __construct(
        public string $name,
        public string $exporter,
        public string $instance,
        public string $matchedOn,
        public string $selector,
        public array $signals = [],
        public array $absent = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'exporter' => $this->exporter,
            'instance' => $this->instance,
            'matchedOn' => $this->matchedOn,
            'selector' => $this->selector,
            'signals' => $this->signals,
            'absent' => $this->absent,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';
        /** @var list<string> $signals */
        $signals = is_array($data['signals'] ?? null) ? array_values(array_filter($data['signals'], 'is_string')) : [];
        /** @var list<string> $absent */
        $absent = is_array($data['absent'] ?? null) ? array_values(array_filter($data['absent'], 'is_string')) : [];

        return new self(
            $string('name'), $string('exporter'), $string('instance'),
            $string('matchedOn'), $string('selector'), $signals, $absent,
        );
    }
}
