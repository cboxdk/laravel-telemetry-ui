<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

/**
 * What we found: which exporters are scraped, which of their instances
 * belong to something this app talks to, and — just as importantly — what
 * did not line up.
 *
 * The unmatched lists are not an afterthought. A wrong or missing match is
 * silent by nature: you get one fewer tile and no error. Recording what we
 * could not place is what turns that silence into something a person can
 * read and act on.
 */
final readonly class InfrastructureMap
{
    /**
     * @param  list<DiscoveredTarget>  $targets
     * @param  list<string>  $exportersPresent  catalogue keys found in the metrics store
     * @param  list<array{exporter: string, instance: string}>  $unmatchedInstances  an exporter instance nothing claims
     * @param  list<string>  $unmatchedHosts  a host we know about with no exporter behind it
     */
    public function __construct(
        public array $targets = [],
        public array $exportersPresent = [],
        public array $unmatchedInstances = [],
        public array $unmatchedHosts = [],
        public int $discoveredAt = 0,
    ) {}

    /**
     * Everything discovered about one name — a host (`web-3`) or a
     * dependency address (`cache-1:6379`).
     *
     * @return list<DiscoveredTarget>
     */
    public function for(string $name): array
    {
        $needle = self::normalise($name);

        return array_values(array_filter(
            $this->targets,
            static fn (DiscoveredTarget $t): bool => self::normalise($t->name) === $needle,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * host:port → host, lowercased. Both sides of a match go through this,
     * so `cache-1:6379` and `Cache-1` are the same machine.
     */
    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        // Strip a scheme (`redis://…`) and anything after the host.
        $value = (string) preg_replace('#^[a-z0-9+.-]+://#', '', $value);
        $value = (string) preg_replace('#[/?].*$#', '', $value);

        return (string) preg_replace('#:\d+$#', '', $value);
    }

    /**
     * @return array{targets: list<array<string, mixed>>, exportersPresent: list<string>, unmatchedInstances: list<array{exporter: string, instance: string}>, unmatchedHosts: list<string>, discoveredAt: int}
     */
    public function toArray(): array
    {
        return [
            'targets' => array_map(static fn (DiscoveredTarget $t): array => $t->toArray(), $this->targets),
            'exportersPresent' => $this->exportersPresent,
            'unmatchedInstances' => $this->unmatchedInstances,
            'unmatchedHosts' => $this->unmatchedHosts,
            'discoveredAt' => $this->discoveredAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $targets */
        $targets = is_array($data['targets'] ?? null) ? $data['targets'] : [];
        /** @var list<string> $present */
        $present = is_array($data['exportersPresent'] ?? null) ? $data['exportersPresent'] : [];
        /** @var list<array{exporter: string, instance: string}> $unmatched */
        $unmatched = is_array($data['unmatchedInstances'] ?? null) ? $data['unmatchedInstances'] : [];
        /** @var list<string> $hosts */
        $hosts = is_array($data['unmatchedHosts'] ?? null) ? $data['unmatchedHosts'] : [];

        return new self(
            array_values(array_map(static fn (array $t): DiscoveredTarget => DiscoveredTarget::fromArray($t), $targets)),
            array_values($present),
            array_values($unmatched),
            array_values($hosts),
            is_int($data['discoveredAt'] ?? null) ? $data['discoveredAt'] : 0,
        );
    }
}
